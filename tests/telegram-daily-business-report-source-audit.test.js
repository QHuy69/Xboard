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
assert.strictEqual(manifest.version, '2.3.3',
  'The split-audience daily-report release must remain versioned as Telegram 2.3.3');

const expectedConfig = {
  enable_daily_business_report: ['boolean', false],
  daily_business_report_time: ['string', '00:30'],
  daily_business_report_chat_id: ['string', ''],
  daily_business_report_send_admin_private: ['boolean', true],
  daily_business_report_publish_group_summary: ['boolean', false],
  daily_business_report_group_include_online_users: ['boolean', false],
  daily_business_report_group_include_traffic: ['boolean', false],
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
assert(!Object.hasOwn(manifest.config, 'daily_business_report_reuse_node_group'),
  'The retired full-report node-group reuse switch is still exposed in admin configuration');

assert(service.includes("public const TIMEZONE = 'Asia/Ho_Chi_Minh';"),
  'The report service does not own the canonical Vietnam timezone');
assert(plugin.includes('private const BUSINESS_TIMEZONE = TelegramDailyBusinessReportService::TIMEZONE;'),
  'The Telegram scheduler and renderer can drift from the report-service timezone');

const schedule = between(plugin, 'public function schedule(Schedule $schedule): void', 'public function handleMessage');
const dailySchedule = between(
  schedule,
  "if ($this->configEnabled('enable_daily_business_report'))",
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
const audienceDispatch = between(
  plugin,
  'protected function sendDailyBusinessAudience',
  'public function sendDatabaseBackup'
);
for (const needle of [
  "CarbonImmutable::now(self::BUSINESS_TIMEZONE)",
  '->subDay()',
  "configEnabled('daily_business_report_send_admin_private')",
  "configEnabled('daily_business_report_publish_group_summary')",
  "'admin_private'",
  "'group_summary'",
]) {
  assert(dispatch.includes(needle), `Daily report dispatch is missing ${needle}`);
}
for (const needle of [
  "'telegram:daily-business-report:dispatch:' . $audience",
  "'telegram:daily-business-report:date:'",
  ". ':audience:'",
  '. $audience',
  'Cache::add($claimKey',
  "Cache::put($claimKey, 'done'",
]) {
  assert(audienceDispatch.includes(needle), `Per-audience delivery is missing ${needle}`);
}
assert(!dispatch.includes("'chat_id' =>"), 'Daily report logs expose the destination Chat ID');
assert(!dispatch.includes("'summary' =>"), 'Daily report logs expose revenue or user-ranking payloads');
assert(!plugin.includes('daily_business_report_reuse_node_group'),
  'The retired node-group reuse setting still affects Telegram runtime behavior');

const privateBranch = between(
  dispatch,
  "if ($this->configEnabled('daily_business_report_send_admin_private'))",
  "if ($this->configEnabled('daily_business_report_publish_group_summary'))"
);
const groupBranch = between(
  dispatch,
  "if ($this->configEnabled('daily_business_report_publish_group_summary'))",
  'protected function sendDailyBusinessAudience'
);
assert(privateBranch.includes("'admin_private'")
  && privateBranch.includes('dailyBusinessReportChunks(')
  && privateBranch.includes('$this->dailyBusinessReportAdminChatId()')
  && privateBranch.includes('TelegramDailyBusinessReportService::class)->summarize($reportDate)'),
  'The full commercial report is not confined to a currently bound administrator audience');
assert(!privateBranch.includes('dailyPublicReportChunks('),
  'The administrator branch unexpectedly uses the reduced public renderer');
assert(groupBranch.includes("'group_summary'") && groupBranch.includes('dailyPublicReportChunks('),
  'The public group audience is not confined to its safe renderer');
assert(!groupBranch.includes('dailyBusinessReportChunks(')
  && !groupBranch.includes('TelegramDailyBusinessReportService::class)->summarize('),
  'The public group branch can render or load the full commercial summary');

const destination = between(plugin, 'protected function dailyBusinessReportChatId', 'protected function dailyBusinessReportAdminChatId');
assert(destination.includes("getConfig('daily_business_report_chat_id', '')")
  && destination.includes("preg_match('/^[1-9][0-9]{0,19}$/', $configured)"),
  'The private administrator destination is not restricted to a positive Telegram user ID');
assert(!destination.includes('nodeReportChatId()'),
  'The private commercial destination can silently fall back to the node-report group');
assert(destination.includes(': null;'), 'Missing commercial destination does not fail closed');

const adminDestination = between(
  plugin,
  'protected function dailyBusinessReportAdminChatId',
  'protected function nodeReportChatId'
);
for (const needle of [
  '$this->dailyBusinessReportChatId()',
  "->where('telegram_id', $chatId)",
  "->where('is_admin', 1)",
  '->exists()',
  ': null;',
]) {
  assert(adminDestination.includes(needle),
    `Private report recipient is not revalidated against an administrator binding: ${needle}`);
}

const validation = between(plugin, 'public function validateActivation', 'protected array $commandConfigs');
for (const needle of [
  "getConfig('daily_business_report_time', '00:30')",
  "configEnabled('enable_daily_business_report')",
  "configEnabled('daily_business_report_send_admin_private')",
  "configEnabled('daily_business_report_publish_group_summary')",
  '$this->dailyBusinessReportAdminChatId()',
  '$this->nodeReportChatId()',
  "preg_match('/^[1-9][0-9]{0,19}$/', $privateConfigured)",
  "preg_match('/^-[1-9][0-9]{0,19}$/', $groupChat)",
  'if (!$sendPrivate && !$publishGroup)',
  'Enable at least one daily business report audience.',
  'throw new \\InvalidArgumentException',
]) {
  assert(validation.includes(needle), `Telegram activation validation is missing ${needle}`);
}
const dormantReturn = validation.indexOf("if (!$this->configEnabled('enable_daily_business_report')) return;");
const dailyTimeValidation = validation.indexOf("getConfig('daily_business_report_time', '00:30')");
const privateValidationStart = validation.indexOf('if ($sendPrivate)');
const privateConfigRead = validation.indexOf("getConfig('daily_business_report_chat_id', '')");
const groupValidationStart = validation.indexOf('if ($publishGroup)');
assert(dormantReturn >= 0 && dormantReturn < dailyTimeValidation,
  'Disabled daily reporting can still block plugin config saves on dormant daily settings');
assert(privateValidationStart > dailyTimeValidation
  && privateConfigRead > privateValidationStart
  && groupValidationStart > privateConfigRead,
  'Disabled private delivery can still block config saves on a dormant private Chat ID');

const publicRender = between(
  plugin,
  'protected function dailyPublicReportChunks',
  'protected function dailyBusinessReportChunks'
);
for (const forbidden of [
  'revenue',
  'order_count',
  'orders',
  'coupon',
  'top_users',
  'top_user',
  'top_servers',
  'top_node',
  'email',
  'dailyBusinessReportChunks',
  'TelegramDailyBusinessReportService',
]) {
  assert(!publicRender.toLowerCase().includes(forbidden.toLowerCase()),
    `Public group renderer exposes or reaches private commercial field: ${forbidden}`);
}
assert(publicRender.includes("configEnabled('daily_business_report_group_include_traffic')"),
  'Public group traffic has no explicit opt-in gate');
assert(publicRender.indexOf("configEnabled('daily_business_report_group_include_traffic')")
  < publicRender.indexOf("DB::table('v2_stat_server')"),
  'Public group traffic is queried before the traffic opt-in gate');
assert.strictEqual((publicRender.match(/DB::table\('v2_stat_server'\)/g) || []).length, 1,
  'Public traffic aggregation has an unexpected path outside its opt-in block');
assert(publicRender.includes("configEnabled('daily_business_report_group_include_online_users')"),
  'Public online-user aggregate has no explicit opt-in gate');
assert(publicRender.indexOf("configEnabled('daily_business_report_group_include_online_users')")
  < publicRender.indexOf("User::where('t', '>=', time() - 600)"),
  'Public online-user count is queried before the online-user opt-in gate');
assert(publicRender.includes('$this->chunkReportLines($lines)'),
  'Public group output is not chunked before delivery');

const privateRender = between(plugin, 'protected function dailyBusinessReportChunks', 'protected function maskDailyBusinessUser');
for (const commercialField of [
  "['revenue']",
  "['order_count']",
  "['top_coupons']",
  "['top_users']",
  "['top_servers']",
]) {
  assert(privateRender.includes(commercialField),
    `Private full report lost commercial field ${commercialField}`);
}
assert(privateRender.includes("$this->maskDailyBusinessUser((string) ($user['email'] ?? ''))"),
  'Top-user report output does not pass through the dedicated masking helper');
assert(privateRender.includes('Helper::escapeMarkdown'), 'Dynamic commercial values are not escaped for Telegram Markdown');
assert(privateRender.includes('mb_substr'), 'Untrusted commercial labels are not length-bounded');
assert(privateRender.includes('$this->chunkReportLines($lines)'), 'Commercial output is not chunked before delivery');

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
  [...privateRender.matchAll(/\$this->text\('(daily_business_[^']+)'/g)].map((match) => match[1])
)].sort();
assert(runtimeDailyKeys.length >= 15,
  `Only ${runtimeDailyKeys.length} daily-report messages are wired into the renderer`);
const runtimePublicKeys = [...new Set(
  [...publicRender.matchAll(/\$this->text\('(daily_public_[^']+)'/g)].map((match) => match[1])
)].sort();
assert(runtimePublicKeys.length >= 6,
  `Only ${runtimePublicKeys.length} public daily-summary messages are wired into the renderer`);
const runtimeLocaleKeys = [...new Set([...runtimeDailyKeys, ...runtimePublicKeys])];

for (const [locale, catalog] of Object.entries(catalogs)) {
  for (const key of runtimeLocaleKeys) {
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

console.log(`Telegram daily business report passed split-audience config, Vietnam schedule, private/public isolation, per-audience dedupe, opt-in aggregates, privacy, chunking and ${expectedLocales.length}-locale source audits.`);
