const assert = require('assert');
const fs = require('fs');

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function includesAll(file, needles) {
  const source = read(file);
  for (const needle of needles) {
    assert(source.includes(needle), `${file} is missing Telegram security contract: ${needle}`);
  }
  return source;
}

const userRoute = includesAll('app/Http/Routes/V1/UserRoute.php', [
  "'middleware' => 'user'",
  "$router->get('/telegram/getBotInfo'",
  "$router->post('/telegram/unbind'"
]);
assert(
  userRoute.indexOf("'middleware' => 'user'") < userRoute.indexOf("$router->get('/telegram/getBotInfo'"),
  'Telegram bot info must remain behind authenticated user middleware'
);

const userController = includesAll('app/Http/Controllers/V1/User/TelegramController.php', [
  "admin_setting('telegram_bot_enable', false)",
  'FILTER_VALIDATE_BOOLEAN',
  "$plugin?->is_enabled",
  "$token !== ''",
  "'linked' => $user->telegram_id !== null",
  "$data['bind_url'] = 'https://t.me/' . $username",
  "if ($user->telegram_id !== null)",
  "$bindingService->issue($user)",
  '$bindingService->revoke($request->user())'
]);
assert(!/'bind_token'\s*=>/.test(userController), 'getBotInfo must not expose a separate bearer token');
assert(!/'telegram_id'\s*=>/.test(userController), 'getBotInfo must not expose the raw Telegram actor id');
assert(
  userController.indexOf('$bindingService->revoke($request->user())') < userController.indexOf('DB::transaction'),
  'outstanding bearer links must be revoked before database unbind'
);

const binding = includesAll('app/Services/TelegramBindingService.php', [
  'public const TOKEN_TTL_SECONDS = 600',
  'random_bytes(24)',
  "hash('sha256', $payload)",
  '$reusable = $this->reusableToken($userId, Cache::get($pointerKey));',
  'Crypt::encryptString($payload)',
  'Crypt::decryptString($encryptedPayload)',
  'public function revoke(User|int $user): void',
  '$record = Cache::get($this->tokenKey($digest))',
  '$targetLock = Cache::lock($this->userLockKey($userId)',
  '$currentDigest = $this->pointerDigest(Cache::get($pointerKey))',
  'hash_equals($currentDigest, $digest)',
  '$claimed = Cache::pull($this->tokenKey($digest))',
  '$actorLock = Cache::lock($this->actorLockKey($telegramActorId)',
  'return DB::transaction',
  "private const MAX_SIGNED_BIGINT = '9223372036854775807'",
  'strcmp($telegramActorId, self::MAX_SIGNED_BIGINT) <= 0'
]);
const targetLock = binding.indexOf('$targetLock->block');
const pointerRecheck = binding.indexOf('$currentDigest = $this->pointerDigest(Cache::get($pointerKey))', targetLock);
const tokenClaim = binding.indexOf('$claimed = Cache::pull($this->tokenKey($digest))', pointerRecheck);
const actorLock = binding.indexOf('$actorLock = Cache::lock', tokenClaim);
const transaction = binding.indexOf('return DB::transaction', actorLock);
assert(
  targetLock >= 0 && targetLock < pointerRecheck && pointerRecheck < tokenClaim
    && tokenClaim < actorLock && actorLock < transaction,
  'consume must serialize target, re-check revocation, atomically claim, serialize actor, then enter the DB transaction'
);

const webhook = includesAll('app/Http/Controllers/V1/Guest/TelegramController.php', [
  'private const UPDATE_DEDUPE_HOURS = 36',
  "admin_setting('telegram_bot_enable', false)",
  "->where('code', 'telegram')",
  "->where('is_enabled', true)",
  'hash_equals($expectedToken, $providedToken)',
  'return Cache::add($key, true, now()->addHours(self::UPDATE_DEDUPE_HOURS))',
  "$this->formatChatJoinRequest($data)",
  "'chat_id' => $chatId",
  "'from_id' => $fromId",
  "private const MAX_SIGNED_BIGINT = '9223372036854775807'"
]);
assert(
  webhook.indexOf('if (!$this->integrationEnabled($botToken))') < webhook.indexOf('hash_equals($expectedToken, $providedToken)'),
  'disabled webhooks must acknowledge before authentication or side effects'
);
assert(
  webhook.indexOf('if (!$this->claimUpdate(') < webhook.indexOf('$this->formatMessage($data)'),
  'atomic update-id claim must happen before any Telegram side effect'
);

const telegramService = read('app/Services/TelegramService.php');
const sendTelegramJob = read('app/Jobs/SendTelegramJob.php');
for (const unsafeLog of [
  "'params' => $params",
  "'error' => $e->getMessage()",
  '$e->getTraceAsString()'
]) {
  assert(!telegramService.includes(unsafeLog), `Telegram logs still expose request data: ${unsafeLog}`);
}
includesAll('app/Services/TelegramService.php', [
  "'chat_id' => (string) $chatId",
  "'user_id' => (string) $userId",
  "'error_type' => $e::class",
  'protected function request(string $method, array $params = [], bool $retryable = false): object',
  '->post($this->apiUrl . $method, $params)',
  'return $retryable ? $request->retry(3, 1000) : $request;',
  "return $this->request('getMe', retryable: true);"
]);
assert(
  telegramService.includes("$this->request('sendMessage', $params);")
    && !telegramService.includes("$this->request('sendMessage', $params, retryable: true)"),
  'Non-idempotent Telegram sendMessage must never opt into HTTP retries.'
);
const constructor = telegramService.slice(
  telegramService.indexOf('public function __construct'),
  telegramService.indexOf('public function sendMessage')
);
assert(!constructor.includes('->retry('), 'Shared Telegram HTTP client still carries retry state into sends.');
assert(sendTelegramJob.includes('public $tries = 1;'),
  'Queued Telegram sends can still retry after an ambiguous successful delivery.');
assert(sendTelegramJob.includes('public $timeout = 40;'),
  'Telegram job timeout can still kill the worker before the 30-second HTTP attempt returns.');
assert(sendTelegramJob.includes('public function __construct(int|string $telegramId, string $text)'),
  'Queued Telegram recipients are still narrowed away from their string bigint boundary.');

includesAll('app/Models/User.php', [
  '@property string|null $telegram_id Telegram ID',
  "'telegram_id' => 'string'"
]);

console.log('Telegram one-time binding, lock order, bigint strings, disabled-state and webhook dedupe contracts verified.');
