const assert = require('assert');
const fs = require('fs');
const path = require('path');

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function between(source, start, end) {
  const from = source.indexOf(start);
  assert(from >= 0, `Missing source boundary: ${start}`);
  const to = source.indexOf(end, from + start.length);
  assert(to > from, `Missing source boundary after ${start}: ${end}`);
  return source.slice(from, to);
}

function placeholders(value) {
  return [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*/g)]
    .map((match) => match[0])
    .sort();
}

const pluginFile = 'plugins-core/Telegram/Plugin.php';
const serviceFile = 'app/Services/TelegramDailyBusinessReportService.php';
const telegramServiceFile = 'app/Services/TelegramService.php';
const manifestFile = 'plugins-core/Telegram/config.json';
const localeDirectory = 'plugins-core/Telegram/locales';
const expectedLocales = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];

const plugin = read(pluginFile);
const service = read(serviceFile);
const telegramService = read(telegramServiceFile);
const manifest = JSON.parse(read(manifestFile));

assert.strictEqual(manifest.auto_update_on_deploy, true,
  'The bundled Telegram upgrade must reach existing installations during deploy');
assert(/^2\.(?:[3-9]|\d{2,})\./.test(manifest.version),
  `Telegram manifest version ${manifest.version} does not include the daily-report release`);

const expectedConfig = {
  enable_daily_business_report: ['boolean', false],
  daily_business_report_time: ['string', '00:30'],
  daily_business_report_chat_id: ['string', ''],
  daily_business_report_reuse_node_group: ['boolean', false],
};
for (const [key, [type, defaultValue]] of Object.entries(expectedConfig)) {
  const field = manifest.config?.[key];
  assert(field, `Telegram manifest is missing ${key}`);
  assert.strictEqual(field.type, type, `${key} has the wrong admin-control type`);
  assert.strictEqual(field.default, defaultValue, `${key} has an unsafe default`);
  assert.strictEqual(typeof field.label, 'string', `${key} needs an admin label`);
  assert.strictEqual(typeof field.description, 'string', `${key} needs an admin description`);
}
assert(manifest.config.daily_business_report_time.description.includes('Asia/Ho_Chi_Minh'),
  'The admin form does not identify the report time as Vietnam time');

assert(service.includes("public const TIMEZONE = 'Asia/Ho_Chi_Minh';"),
  'The report service does not own the canonical Vietnam timezone');
assert(plugin.includes('private const BUSINESS_TIMEZONE = TelegramDailyBusinessReportService::TIMEZONE;'),
  'The Telegram scheduler and renderer can drift from the report-service timezone');

const schedule = between(plugin, 'public function schedule(Schedule $schedule): void', 'public function handleMessage');
const dailySchedule = between(
  schedule,
  "if ($this->getConfig('enable_daily_business_report', false))",
  "if ($this->getConfig('enable_database_backup', false))"
);
for (const needle of [
  'sendDailyBusinessReport()',
  "name('telegram-daily-business-report')",
  '->everyFiveMinutes()',
  '->onOneServer()',
  '->withoutOverlapping(60)',
]) {
  assert(dailySchedule.includes(needle), `Daily Telegram schedule is missing ${needle}`);
}

const dispatch = between(plugin, 'public function sendDailyBusinessReport', 'public function sendDatabaseBackup');
for (const needle of [
  "CarbonImmutable::now(self::BUSINESS_TIMEZONE)",
  '->subDay()',
  "Cache::lock('telegram:daily-business-report:dispatch'",
  "'telegram:daily-business-report:date:' . $reportDate",
  'Cache::add($claimKey',
  "Cache::put($claimKey, 'done'",
  "'destination_hash' => substr(hash('sha256', $chatId)",
]) {
  assert(dispatch.includes(needle), `Daily report dispatch is missing ${needle}`);
}
assert(!dispatch.includes("'chat_id' =>"), 'Daily report logs expose the destination Chat ID');
assert(!dispatch.includes("'summary' =>"), 'Daily report logs expose revenue or user-ranking payloads');

const destination = between(plugin, 'protected function dailyBusinessReportChatId', 'protected function nodeReportChatId');
const explicitDestination = destination.indexOf("getConfig('daily_business_report_chat_id', '')");
const reuseOptIn = destination.indexOf("getConfig('daily_business_report_reuse_node_group', false)");
const nodeFallback = destination.indexOf('return $this->nodeReportChatId()');
assert(explicitDestination >= 0 && reuseOptIn > explicitDestination && nodeFallback > reuseOptIn,
  'Commercial destination must prefer its separate Chat ID and reuse the node group only after explicit opt-in');
assert(destination.includes('return null;'), 'Missing commercial destination does not fail closed');

const validation = between(plugin, 'public function validateActivation', 'protected array $commandConfigs');
for (const needle of [
  "getConfig('daily_business_report_time', '00:30')",
  "getConfig('enable_daily_business_report', false)",
  '$this->dailyBusinessReportChatId()',
  'throw new \\InvalidArgumentException',
]) {
  assert(validation.includes(needle), `Telegram activation validation is missing ${needle}`);
}

const render = between(plugin, 'protected function dailyBusinessReportChunks', 'protected function formatTrafficBytes');
assert(render.includes("$this->maskDailyBusinessUser((string) ($user['email'] ?? ''))"),
  'Top-user report output does not pass through the dedicated masking helper');
assert(render.includes('Helper::escapeMarkdown'), 'Dynamic commercial values are not escaped for Telegram Markdown');
assert(render.includes('mb_substr'), 'Untrusted commercial labels are not length-bounded');
assert(render.includes('$this->chunkReportLines($lines)'), 'Commercial output is not chunked before delivery');

const maskHelper = between(plugin, 'protected function maskDailyBusinessUser', 'protected function chunkReportLines');
assert(maskHelper.includes("'***@'"), 'Email masking does not hide the local part');
assert(!maskHelper.includes('return $value;'), 'The masking helper can return a raw user identifier');

const chunkLimit = plugin.match(/private const NODE_REPORT_MESSAGE_LIMIT\s*=\s*(\d+);/);
assert(chunkLimit, 'Telegram report chunk limit is missing');
assert(Number(chunkLimit[1]) > 0 && Number(chunkLimit[1]) <= 4096,
  'Telegram report chunks can exceed the Bot API text limit');

const requestMethod = between(telegramService, 'protected function request(', 'protected function http(');
assert(requestMethod.includes('->post($this->apiUrl . $method, $params)'),
  'Telegram mutations still place report contents in a GET query string');
assert(!requestMethod.includes("'params' => $params"),
  'Telegram API failures still log complete report payloads');
assert(!requestMethod.includes('$e->getMessage()'),
  'Telegram API failures may leak the bot token through exception messages');

const catalogs = Object.fromEntries(expectedLocales.map((locale) => [
  locale,
  JSON.parse(read(path.join(localeDirectory, `${locale}.json`))),
]));
const runtimeDailyKeys = [...new Set(
  [...render.matchAll(/\$this->text\('(daily_business_[^']+)'/g)].map((match) => match[1])
)].sort();
assert(runtimeDailyKeys.length >= 15,
  `Only ${runtimeDailyKeys.length} daily-report messages are wired into the renderer`);

for (const [locale, catalog] of Object.entries(catalogs)) {
  for (const key of runtimeDailyKeys) {
    const value = catalog.messages?.[key];
    assert.strictEqual(typeof value, 'string', `${locale}.messages.${key} is missing`);
    assert(value.trim() !== '', `${locale}.messages.${key} is empty`);
    assert.deepStrictEqual(
      placeholders(value),
      placeholders(catalogs.en.messages[key]),
      `${locale}.messages.${key} changed interpolation placeholders`
    );
  }
}

for (const locale of expectedLocales.filter((locale) => locale !== 'en')) {
  assert.notStrictEqual(
    catalogs[locale].messages.daily_business_title,
    catalogs.en.messages.daily_business_title,
    `${locale} daily report title falls back to English`
  );
}

console.log(`Telegram daily business report passed config, Vietnam schedule, destination, dedupe, privacy, chunking and ${expectedLocales.length}-locale source audits.`);
