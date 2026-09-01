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
  "$data['bind_url'] = 'https://t.me/' . $username . '?start=menu';",
  "if ($user->telegram_id !== null)",
  "$bindingService->issue($user)",
  '$bindingService->revoke($request->user())'
]);
assert(!/'bind_token'\s*=>/.test(userController), 'getBotInfo must not expose a separate bearer token');
assert(!/'telegram_id'\s*=>/.test(userController), 'getBotInfo must not expose the raw Telegram actor id');
assert(!/'(?:subscription_url|subscribe_url)'\s*=>/.test(userController),
  'getBotInfo must not expose a subscription credential');
assert(
  userController.indexOf('$bindingService->revoke($request->user())') < userController.indexOf('DB::transaction'),
  'outstanding bearer links must be revoked before database unbind'
);

const adminConfigController = read('app/Http/Controllers/V2/Admin/ConfigController.php');
const webhookSetup = adminConfigController.slice(
  adminConfigController.indexOf('public function setTelegramWebhook'),
  adminConfigController.indexOf('public function fetch')
);
for (const tokenContract of [
  "$submittedToken = trim((string) $request->input('telegram_bot_token', ''));",
  "$botToken = $submittedToken !== ''",
  ": trim((string) admin_setting('telegram_bot_token', ''));",
  "'access_token' => md5($botToken)",
  '$telegramService = new TelegramService($botToken);',
  '$commandMenuCleared = $telegramService->registerBotCommands();',
  "'command_menu_cleared' => $commandMenuCleared",
  "'command_menu_reconciliation_pending' => !$commandMenuCleared",
]) {
  assert(webhookSetup.includes(tokenContract),
    `Webhook token selection is missing: ${tokenContract}`);
}
const selectToken = webhookSetup.indexOf("$botToken = $submittedToken !== ''");
const hashToken = webhookSetup.indexOf("'access_token' => md5($botToken)");
const constructService = webhookSetup.indexOf('$telegramService = new TelegramService($botToken);');
assert(selectToken >= 0 && selectToken < hashToken && hashToken < constructService,
  'Webhook access hash and Telegram API client are not derived from one selected request-or-stored token.');
assert(!webhookSetup.includes("md5(admin_setting('telegram_bot_token'"),
  'Webhook setup can hash a different stored token from the Telegram API client.');

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
  "private const UPDATE_RECEIPTS_TABLE = 'telegram_webhook_update_receipts'",
  "admin_setting('telegram_bot_enable', false)",
  "->where('code', 'telegram')",
  "->where('is_enabled', true)",
  'hash_equals($expectedToken, $providedToken)',
  "hash('sha256', $botToken, true) . \"\\0\" . $updateId",
  'return DB::transaction(function () use ($receiptHash, $claimedAt): bool',
  "->where('expires_at', '<=', $claimedAt)",
  "'expires_at' => $claimedAt->copy()->addHours(self::UPDATE_DEDUPE_HOURS)",
  '->insertOrIgnore([',
  '}, 3);',
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
const claimUpdate = webhook.slice(
  webhook.indexOf('private function claimUpdate'),
  webhook.indexOf('private function actorId')
);
assert(!claimUpdate.includes('Cache::'), 'Webhook replay protection must not depend on an evictable cache receipt');
assert(!claimUpdate.includes('catch ('), 'Receipt database failures must propagate before Telegram side effects');

const receiptMigration = includesAll('database/migrations/2026_09_01_000002_create_telegram_webhook_update_receipts.php', [
  "private const TABLE = 'telegram_webhook_update_receipts'",
  "$table->char('receipt_hash', 64)",
  "$table->timestamp('created_at')",
  "$table->timestamp('expires_at')",
  "$table->unique('receipt_hash', self::UNIQUE_INDEX)",
  "$table->index('expires_at', self::EXPIRY_INDEX)",
  'Schema::dropIfExists(self::TABLE)'
]);
assert(!receiptMigration.includes('bot_token') && !receiptMigration.includes('update_id'),
  'Durable receipts must not retain the bot credential or raw Telegram update id');

includesAll('tests/Feature/TelegramBindingWebhookSecurity20260901Test.php', [
  'Cache::flush();',
  "$this->assertDatabaseCount('telegram_webhook_update_receipts', 1)",
  'test_webhook_receipt_claim_prunes_expired_rows_but_keeps_live_receipts'
]);

const telegramService = read('app/Services/TelegramService.php');
const sendTelegramJob = read('app/Jobs/SendTelegramJob.php');
const telegramPlugin = read('plugins-core/Telegram/Plugin.php');
for (const unsafeLog of [
  "'params' => $params",
  "'error' => $e->getMessage()",
  '$e->getTraceAsString()'
]) {
  assert(!telegramService.includes(unsafeLog), `Telegram logs still expose request data: ${unsafeLog}`);
}
includesAll('app/Services/TelegramService.php', [
  "private const BOT_COMMAND_LANGUAGE_CODES = ['vi', 'en', 'zh', 'ja', 'ko', 'fa', 'ru']",
  "'all_private_chats'",
  "'all_group_chats'",
  "'all_chat_administrators'",
  "private const BOT_COMMAND_MENU_SCHEMA = 'inline-buttons-v2.3'",
  "private const BOT_COMMAND_MENU_FINGERPRINT_SETTING = 'telegram_bot_command_menu_fingerprint'",
  "'chat_id' => (string) $chatId",
  "'user_id' => (string) $userId",
  "'error_type' => $e::class",
  'protected function request(string $method, array $params = [], bool $retryable = false): object',
  '->post($this->apiUrl . $method, $params)',
  'return $retryable ? $request->retry(3, 1000) : $request;',
  "return $this->request('getMe', retryable: true);"
]);
const commandMenuRegistration = telegramService.slice(
  telegramService.indexOf('public function registerBotCommands'),
  telegramService.indexOf('public function commandMenuNeedsReconciliation')
);
assert(commandMenuRegistration.includes('$this->deleteMyCommands();'),
  'Webhook setup must clear legacy Telegram command menus');
assert(!commandMenuRegistration.includes("$this->request('setMyCommands'"),
  'Webhook setup must not publish slash commands');
const deleteBeforeFingerprint = commandMenuRegistration.indexOf('$this->deleteMyCommands();');
const persistFingerprint = commandMenuRegistration.indexOf(
  'self::BOT_COMMAND_MENU_FINGERPRINT_SETTING => $this->commandMenuFingerprint'
);
assert(deleteBeforeFingerprint >= 0 && deleteBeforeFingerprint < persistFingerprint,
  'A command-menu fingerprint can be persisted before every scope is cleared successfully.');
assert(commandMenuRegistration.includes('return false;')
  && commandMenuRegistration.includes('return true;'),
  'Command-menu cleanup does not expose success/failure for scheduled reconciliation.');
const menuReconciliation = telegramService.slice(
  telegramService.indexOf('public function commandMenuNeedsReconciliation'),
  telegramService.indexOf('public function getMyCommands')
);
assert(menuReconciliation.includes('if (!$this->hasBotToken) return false;')
  && menuReconciliation.includes('admin_setting(self::BOT_COMMAND_MENU_FINGERPRINT_SETTING')
  && menuReconciliation.includes('!hash_equals($this->commandMenuFingerprint, $stored)'),
  'Command-menu reconciliation is not bound to the current bot-token fingerprint.');
const commandMenuDeletion = telegramService.slice(
  telegramService.indexOf('public function deleteMyCommands'),
  telegramService.indexOf('public function sendMessageWithAdmin')
);
assert(commandMenuDeletion.includes('foreach (self::BOT_COMMAND_SCOPE_TYPES as $scopeType)')
    && commandMenuDeletion.includes('foreach ([null, ...self::BOT_COMMAND_LANGUAGE_CODES] as $languageCode)'),
  'Every global chat scope and supported Telegram language scope must have stale commands deleted');
assert(commandMenuDeletion.includes("'scope' => json_encode(['type' => $scopeType])")
    && commandMenuDeletion.includes("'language_code' => $languageCode")
    && commandMenuDeletion.includes("static fn ($value) => $value !== null"),
  'Command deletion must send the matching global scope while omitting language_code for its default locale');
assert(commandMenuDeletion.includes('$runPool = function (array $batch, int $concurrency): array')
    && commandMenuDeletion.includes('return Http::pool(')
    && commandMenuDeletion.includes('->timeout(10)')
    && commandMenuDeletion.includes('$responses = $runPool($operations, 8);')
    && commandMenuDeletion.includes('foreach ($operations as $key => $operation)')
    && commandMenuDeletion.includes('$failedScopes[$key] = [')
    && commandMenuDeletion.includes("throw new ApiException('Telegram command menu cleanup was incomplete')"),
  'A failed scope must be aggregated after the complete finite deletion matrix, not reported as complete');
assert((commandMenuDeletion.match(/\(\$validated->result \?\? null\) !== true/g) || []).length === 2,
  'Both first-pass and retry cleanup must require Telegram result === true.');
for (const rateLimitContract of [
  "$response->status() === 429",
  "$response->json('error_code', 0) === 429",
  '$rateLimitedScopes[$key] = $operation;',
  "$response->json('parameters.retry_after', 0)",
  '$boundedDelay = min(15, $retryAfterSeconds);',
  'if ($boundedDelay > 0) sleep($boundedDelay);',
  '$retryResponses = $runPool($rateLimitedScopes, 4);',
  'foreach ($rateLimitedScopes as $key => $operation)',
  'unset($failedScopes[$key]);',
]) {
  assert(commandMenuDeletion.includes(rateLimitContract),
    `Rate-limited command cleanup contract is missing: ${rateLimitContract}`);
}
assert(commandMenuDeletion.indexOf('$responses = $runPool($operations, 8);')
    < commandMenuDeletion.indexOf('$retryResponses = $runPool($rateLimitedScopes, 4);'),
  'Affected-scope retry can run before the complete first-pass matrix.');
const scheduledMenuCleanup = telegramPlugin.slice(
  telegramPlugin.indexOf('public function schedule'),
  telegramPlugin.indexOf('public function handleMessage')
);
for (const scheduledContract of [
  '$this->telegramService->commandMenuNeedsReconciliation()',
  '$this->telegramService->registerBotCommands()',
  "->name('telegram-command-menu-reconcile')",
  '->everyFiveMinutes()',
  '->onOneServer()',
  '->withoutOverlapping(5)',
]) {
  assert(scheduledMenuCleanup.includes(scheduledContract),
    `Scheduled command-menu retry is missing: ${scheduledContract}`);
}
assert(
  telegramService.includes("$this->request('sendMessage', $params);")
    && !telegramService.includes("$this->request('sendMessage', $params, retryable: true)"),
  'Non-idempotent Telegram sendMessage must never opt into HTTP retries.'
);
const constructor = telegramService.slice(
  telegramService.indexOf('public function __construct'),
  telegramService.indexOf('public function sendMessage')
);
assert(constructor.includes("$botToken = trim((string) ($token ?? admin_setting('telegram_bot_token', '')));"),
  'An explicit rotation token does not take precedence over the stored Telegram token.');
for (const fingerprintPart of [
  'self::BOT_COMMAND_MENU_SCHEMA,',
  'implode(\',\', self::BOT_COMMAND_SCOPE_TYPES),',
  "'default-language,' . implode(',', self::BOT_COMMAND_LANGUAGE_CODES),",
  '$botToken,',
]) {
  assert(constructor.includes(fingerprintPart),
    `Command-menu fingerprint omits deployed schema input: ${fingerprintPart}`);
}
assert(!constructor.includes("admin_setting('telegram_bot_token', $token)"),
  'TelegramService still treats the explicit rotation token as a fallback default.');
assert(!constructor.includes('->retry('), 'Shared Telegram HTTP client still carries retry state into sends.');
assert(sendTelegramJob.includes('public $tries = 1;'),
  'Queued Telegram sends can still retry after an ambiguous successful delivery.');
assert(sendTelegramJob.includes('public $timeout = 40;'),
  'Telegram job timeout can still kill the worker before the 30-second HTTP attempt returns.');
assert(sendTelegramJob.includes('public function __construct(int|string $telegramId, string $text)'),
  'Queued Telegram recipients are still narrowed away from their string bigint boundary.');
assert(sendTelegramJob.includes('if (!TelegramService::runtimeEnabled()) return;'),
  'A queued Telegram notification can outlive and bypass the current runtime switches.');

const runtimeGate = telegramService.slice(
  telegramService.indexOf('public static function runtimeEnabled'),
  telegramService.indexOf('public function sendMessage')
);
for (const runtimeContract of [
  "admin_setting('telegram_bot_enable', false)",
  'FILTER_VALIDATE_BOOLEAN',
  "admin_setting('telegram_bot_token', '')",
  "->where('code', 'telegram')",
  "->where('is_enabled', true)",
]) {
  assert(runtimeGate.includes(runtimeContract),
    `Central Telegram runtime gate is incomplete: ${runtimeContract}`);
}
const runtimeSend = telegramService.slice(
  telegramService.indexOf('public function sendMessage'),
  telegramService.indexOf('public function sendDocument')
);
assert(runtimeSend.includes('if (!self::runtimeEnabled()) return;'),
  'Direct Telegram messages can bypass the central runtime gate.');
const controlPlane = telegramService.slice(
  telegramService.indexOf('public function getMe'),
  telegramService.indexOf('public function sendMessageWithAdmin')
);
for (const method of ['getMe', 'setWebhook', 'registerBotCommands', 'deleteMyCommands']) {
  const start = controlPlane.indexOf(`public function ${method}`);
  assert(start >= 0, `Missing Telegram control-plane method: ${method}`);
  const next = controlPlane.indexOf('public function ', start + 1);
  const body = controlPlane.slice(start, next < 0 ? controlPlane.length : next);
  assert(!body.includes('runtimeEnabled()'),
    `Control-plane method ${method} is incorrectly blocked by the runtime delivery switch.`);
}

const backupDelivery = telegramPlugin.slice(
  telegramPlugin.indexOf('public function sendDatabaseBackup'),
  telegramPlugin.indexOf('public function handleResellerCommand')
);
assert(backupDelivery.indexOf('if (!TelegramService::runtimeEnabled())')
    < backupDelivery.indexOf('app(EncryptedDatabaseBackupService::class)->create($password)'),
  'Telegram backup can create a sensitive artifact before the runtime gate.');

includesAll('app/Models/User.php', [
  '@property string|null $telegram_id Telegram ID',
  "'telegram_id' => 'string'"
]);

console.log('Telegram one-time binding, lock order, bigint strings, disabled-state and webhook dedupe contracts verified.');
