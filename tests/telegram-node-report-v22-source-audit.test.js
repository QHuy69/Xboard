const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const plugin = read('plugins-core/Telegram/Plugin.php');
const telegramService = read('app/Services/TelegramService.php');
const config = JSON.parse(read('plugins-core/Telegram/config.json'));
const readme = read('plugins-core/Telegram/README.md');

function between(source, start, end) {
  const startIndex = source.indexOf(start);
  assert(startIndex >= 0, `Missing source marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert(endIndex > startIndex, `Missing source marker after ${start}: ${end}`);
  return source.slice(startIndex, endIndex);
}

function includesAll(source, needles, contract) {
  for (const needle of needles) {
    assert(source.includes(needle), `${contract} is missing: ${needle}`);
  }
}

const reportConfig = config.config;
assert.strictEqual(reportConfig.enable_node_group_report.type, 'boolean');
assert.strictEqual(reportConfig.enable_node_group_report.default, false,
  'Periodic node reporting must remain disabled by default.');
assert.strictEqual(reportConfig.node_report_chat_id.type, 'string');
assert.strictEqual(reportConfig.node_report_chat_id.default, '',
  'A report destination must never be guessed.');
assert.strictEqual(reportConfig.node_report_locale.type, 'select');
assert.strictEqual(reportConfig.node_report_locale.default, 'vi');
assert.deepStrictEqual(reportConfig.node_report_locale.options.map(({ value }) => value),
  ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'],
  'Scheduled-report language selector must cover all bot locales.');
assert.deepStrictEqual(reportConfig.node_report_interval_minutes.options.map(({ value }) => value),
  ['5', '15', '60'], 'Node report interval choices drifted outside the safe bounded set.');
assert.strictEqual(reportConfig.node_report_interval_minutes.default, '15');

const schedule = between(plugin, 'public function schedule', 'public function handleMessage');
includesAll(schedule, [
  "if ($this->getConfig('enable_node_group_report', false))",
  '$interval = $this->nodeReportInterval();',
  "->name('telegram-node-group-report')",
  '->onOneServer()',
  '->withoutOverlapping(10)',
  '5 => $event->everyFiveMinutes()',
  '60 => $event->hourly()',
  'default => $event->everyFifteenMinutes()',
], 'Replica-safe independent node-report schedule');
assert.strictEqual((schedule.match(/name\('telegram-node-group-report'\)/g) || []).length, 1,
  'The node report scheduler is registered more than once.');

const dispatch = between(plugin, 'public function sendScheduledNodeReport', 'public function sendDatabaseBackup');
includesAll(dispatch, [
  "if (!$this->getConfig('enable_node_group_report', false)) return;",
  'if (!TelegramService::runtimeEnabled())',
  '$chatId = $this->nodeReportChatId();',
  'if ($chatId === null)',
  "Cache::lock('telegram:node-report:dispatch', 300)",
  '$slot = intdiv(time(), $interval * 60);',
  "$claimKey = 'telegram:node-report:slot:' . $interval . ':' . $slot;",
  "Cache::add($claimKey, 'processing', ($interval * 60) + 120)",
  '$chunks = $this->nodeReportChunks($this->nodeReportLocale());',
  "sendMessage($chatId, $chunk, 'markdown')",
  "'action' => 'node_report'",
  "'error_type' => $e::class",
], 'Guarded scheduled node-report delivery');

const runtimeGate = between(telegramService, 'public static function runtimeEnabled', 'public function sendMessage');
includesAll(runtimeGate, [
  "admin_setting('telegram_bot_enable', false)",
  'FILTER_VALIDATE_BOOLEAN',
  "admin_setting('telegram_bot_token', '')",
  "->where('code', 'telegram')",
  "->where('is_enabled', true)",
], 'Central Telegram runtime delivery gate');
assert(!dispatch.includes('(int) admin_setting') && !dispatch.includes('(int) $this->getConfig'),
  'Telegram destination identifiers are cast to platform-sized integers.');
assert(!dispatch.includes("'chat_id' =>") && !dispatch.includes('->getMessage()'),
  'Node report logging exposes a chat identifier, token-bearing URL, or raw exception.');

const setReportGroup = between(
  plugin,
  'public function handleSetReportGroupCommand',
  'public function handleSetBackupChatCommand'
);
includesAll(setReportGroup, [
  'if (!$this->isAdmin($msg))',
  "$chatId = (string) $msg->chat_id;",
  "app(PluginConfigService::class)->updateConfig('telegram'",
  "'node_report_chat_id' => $chatId",
  "'telegram_node_report_chat_id' => $chatId",
], 'Admin-only global report destination mutation');
assert(!setReportGroup.includes('$this->isOperator($msg)'),
  'Ordinary staff can still redirect the global Telegram node-report destination.');

const activationValidation = between(plugin, 'public function validateActivation', 'protected array $commandConfigs');
includesAll(activationValidation, [
  "getConfig('node_report_chat_id', '')",
  "preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId)",
  'throw new \\InvalidArgumentException',
], 'Invalid explicit node-report Chat ID rejection');
includesAll(dispatch, [
  "Log::notice('Telegram scheduled node report sent'",
  "'chunk_count' => count($chunks)",
  "'destination_hash' => substr(hash('sha256', $chatId), 0, 12)",
], 'Positive privacy-safe node-report delivery evidence');

const interval = between(plugin, 'protected function nodeReportInterval', 'protected function nodeReportChatId');
includesAll(interval, [
  "$raw = $this->getConfig('node_report_interval_minutes', '15');",
  "is_scalar($raw) || $raw === null ? trim((string) $raw) : '15'",
  "in_array($value, ['5', '15', '60'], true)",
  '? (int) $value : 15',
], 'Strict report interval parsing');

const destination = between(plugin, 'protected function nodeReportChatId', 'protected function nodeReportLocale');
includesAll(destination, [
  "getConfig('node_report_chat_id', '')",
  "if ($configured !== '')",
  "preg_match('/^-?[1-9][0-9]{0,19}$/', $configured)",
  "admin_setting('telegram_node_report_chat_id', '')",
  "preg_match('/^-?[1-9][0-9]{0,19}$/', $legacy)",
], 'Signed-string destination with legacy fallback');
assert(destination.indexOf("getConfig('node_report_chat_id', '')")
  < destination.indexOf("admin_setting('telegram_node_report_chat_id', '')"),
  'Legacy /setreportgroup destination overrides explicit plugin configuration.');

const reportLines = between(plugin, 'protected function nodeReportLines', 'protected function nodeReport(string');
includesAll(reportLines, [
  '$availability = (int) $server->available_status;',
  "CacheKey::get(",
  "'_ONLINE_USER'",
  '$onlineTelemetry = Cache::get($onlineCacheKey);',
  '$availability === Server::STATUS_ONLINE',
  'is_numeric($onlineTelemetry)',
  '$availability === Server::STATUS_ONLINE_NO_PUSH',
  "$this->text('nodes_unavailable', $locale)",
  "preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', trim((string) $server->name))",
  "preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', strtoupper(trim((string) $server->type)))",
  '$name = mb_substr($name, 0, 160);',
  '$type = mb_substr($type, 0, 64);',
  "'name' => Helper::escapeMarkdown(",
  "'type' => Helper::escapeMarkdown(",
  "'status' => Helper::escapeMarkdown(",
], 'Fresh telemetry and escaped localized node lines');
assert(!reportLines.includes('(int) $server->online'),
  'Missing telemetry can still masquerade as the model accessor fallback zero.');
assert(reportLines.indexOf('is_numeric($onlineTelemetry)')
  < reportLines.indexOf("$this->text('nodes_online_count'"),
  'Online count is rendered before fresh cache telemetry is proven.');

const chunks = between(plugin, 'protected function nodeReportChunks', 'protected function extractTokenFromUrl');
includesAll(plugin, [
  'private const NODE_REPORT_MESSAGE_LIMIT = 2400;',
  'foreach ($this->nodeReportChunks($this->localeForMessage($msg)) as $chunk)',
], 'Manual report splitting');
includesAll(chunks, [
  'mb_strlen($candidate) > self::NODE_REPORT_MESSAGE_LIMIT',
  '$chunks[] = $current;',
  '$current = $header . "\\n\\n" . $line;',
  "rtrim(mb_substr($line, 0, $entryLimit - 1), '\\\\') . '…'",
], 'Line-safe Telegram message splitting');

const localeNames = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];
const keys = [
  'nodes_title', 'node_line', 'nodes_empty', 'node_status_online',
  'node_status_no_data', 'node_status_offline', 'nodes_online_count', 'nodes_unavailable',
];
const catalogs = Object.fromEntries(localeNames.map((locale) => [
  locale,
  JSON.parse(read(`plugins-core/Telegram/locales/${locale}.json`)).messages,
]));
const placeholders = (value) => [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*/g)]
  .map((match) => match[0]).sort();
for (const locale of localeNames) {
  for (const key of keys) {
    assert(typeof catalogs[locale][key] === 'string' && catalogs[locale][key].trim(),
      `${locale} is missing ${key}.`);
    assert.deepStrictEqual(placeholders(catalogs[locale][key]), placeholders(catalogs.en[key]),
      `${locale}.${key} changed node-report placeholders.`);
    if (locale !== 'en' && key !== 'node_line') {
      assert.notStrictEqual(catalogs[locale][key], catalogs.en[key],
        `${locale}.${key} silently falls back to English.`);
    }
  }
}

assert(readme.includes('mặc định là 15 phút')
  && readme.includes('5, 15 hoặc 60 phút')
  && readme.includes('không khả dụng thay vì hiển thị số 0 giả')
  && readme.includes('chia thành nhiều tin'),
  'README omits timing, telemetry-unavailable, or long-message behavior.');

console.log('Telegram node report v2.2 config, strict timing, scheduler dedupe, fresh telemetry, escaping, splitting and 8-locale contracts verified.');
