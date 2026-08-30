const assert = require('assert');
const fs = require('fs');
const path = require('path');

const pluginFile = 'plugins-core/Telegram/Plugin.php';
const localeDirectory = 'plugins-core/Telegram/locales';
const expectedLocales = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];
const expectedSections = ['messages', 'commands', 'periods'];
const plugin = fs.readFileSync(pluginFile, 'utf8');
const catalogs = Object.fromEntries(expectedLocales.map((locale) => [
  locale,
  JSON.parse(fs.readFileSync(path.join(localeDirectory, `${locale}.json`), 'utf8'))
]));

const actualCatalogFiles = fs.readdirSync(localeDirectory)
  .filter((file) => file.endsWith('.json'))
  .map((file) => file.replace(/\.json$/, ''))
  .sort();
assert.deepStrictEqual(actualCatalogFiles, [...expectedLocales].sort(),
  'Telegram locale directory must contain exactly the supported eight catalogs');

function placeholders(value) {
  return [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*/g)]
    .map((match) => match[0])
    .sort();
}

function assertBalancedMarkdown(locale, key, value) {
  for (const marker of ['`', '*']) {
    const count = [...String(value)].filter((character) => character === marker).length;
    assert.strictEqual(count % 2, 0, `${locale}.${key} has unbalanced Telegram Markdown marker ${marker}`);
  }
}

const reference = catalogs.en;
for (const [locale, catalog] of Object.entries(catalogs)) {
  assert.deepStrictEqual(Object.keys(catalog).sort(), [...expectedSections].sort(),
    `${locale} must contain only messages, commands and periods sections`);

  for (const section of expectedSections) {
    const expectedKeys = Object.keys(reference[section]).sort();
    const actualKeys = Object.keys(catalog[section]).sort();
    assert.deepStrictEqual(actualKeys, expectedKeys,
      `${locale}.${section} key coverage differs from English`);

    for (const key of expectedKeys) {
      const value = catalog[section][key];
      assert.strictEqual(typeof value, 'string', `${locale}.${section}.${key} must be a string`);
      assert(value.trim() !== '', `${locale}.${section}.${key} must not be empty`);
      assert.deepStrictEqual(placeholders(value), placeholders(reference[section][key]),
        `${locale}.${section}.${key} changed interpolation placeholders`);
      if (section === 'messages') assertBalancedMarkdown(locale, key, value);
    }
  }

  for (const description of Object.values(catalog.commands)) {
    assert(description.length <= 256, `${locale} has a Telegram command description longer than 256 characters`);
  }
}

const criticalKeys = [
  'busy', 'welcome', 'not_bound', 'traffic', 'url', 'nodes_title', 'report_group_ok',
  'backup_started', 'backup_failed', 'reseller_intro', 'reseller_done', 'renewed',
  'upgraded', 'purchased', 'traffic_reset', 'url_reset', 'ticket_replied',
  'payment_received', 'ticket_notify', 'button_traffic', 'button_url', 'button_nodes',
  'button_reseller', 'button_cancel', 'button_create'
];
for (const locale of expectedLocales.filter((candidate) => candidate !== 'en')) {
  const englishFallbacks = criticalKeys.filter((key) => catalogs[locale].messages[key] === catalogs.en.messages[key]);
  assert.deepStrictEqual(englishFallbacks, [], `${locale} falls back to English in critical Telegram flows`);
}
for (const locale of ['vi', 'en', 'ja', 'ko', 'fa', 'ru']) {
  const chineseFallbacks = criticalKeys.filter((key) =>
    catalogs[locale].messages[key] === catalogs['zh-CN'].messages[key]
    || catalogs[locale].messages[key] === catalogs['zh-TW'].messages[key]
  );
  assert.deepStrictEqual(chineseFallbacks, [], `${locale} incorrectly falls back to a Chinese Telegram catalog`);
}

for (const locale of ['vi', 'en', 'ko', 'fa', 'ru']) {
  const leaks = Object.entries(catalogs[locale].messages).filter(([, value]) => /[\u3400-\u9fff]/.test(value));
  assert.deepStrictEqual(leaks, [], `${locale} contains source-language Chinese fragments`);
}
assert(/[\u3040-\u30ff]/.test(catalogs.ja.messages.welcome), 'Japanese Telegram copy is not localized');
assert(/[\uac00-\ud7af]/.test(catalogs.ko.messages.welcome), 'Korean Telegram copy is not localized');
assert(/[\u0600-\u06ff]/.test(catalogs.fa.messages.welcome), 'Persian Telegram copy is not localized');
assert(/[\u0400-\u04ff]/.test(catalogs.ru.messages.welcome), 'Russian Telegram copy is not localized');
assert(/[\u4e00-\u9fff]/.test(catalogs['zh-CN'].messages.welcome), 'Simplified Chinese Telegram copy is missing');
assert(/[帳號連結與機器人]/.test(catalogs['zh-TW'].messages.welcome), 'Traditional Chinese Telegram copy is missing');

const supportedLiteral = "private const SUPPORTED_LOCALES = ['vi', 'en', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fa', 'ru'];";
assert(plugin.includes(supportedLiteral), 'Plugin supported-locale list is incomplete or reordered');
const runtimeMessageKeys = [...plugin.matchAll(/\$this->text\('([^']+)'/g)].map((match) => match[1]);
const missingRuntimeMessages = [...new Set(runtimeMessageKeys)].filter((key) => !(key in reference.messages));
assert.deepStrictEqual(missingRuntimeMessages, [],
  `Telegram runtime refers to missing message keys: ${JSON.stringify(missingRuntimeMessages)}`);
assert(plugin.includes('foreach (self::SUPPORTED_LOCALES as $locale)'),
  'Reply-keyboard command matching does not cover every locale');
for (const mapping of [
  "'zh-tw', 'zh-hk', 'zh-mo', 'zh-hant', 'zh-cht'",
  "'zh-cn', 'zh-sg', 'zh-hans', 'zh-chs'",
  "'ja', 'jp' => 'ja'",
  "'ko', 'kr' => 'ko'",
  "'fa', 'per' => 'fa'",
  "'ru' => 'ru'"
]) {
  assert(plugin.includes(mapping), `Telegram locale normalization is missing: ${mapping}`);
}

for (const key of ['payment_received', 'ticket_notify', 'backup_caption', 'expires_never', 'nodes_empty']) {
  assert(plugin.includes(`$this->text('${key}'`), `Telegram runtime does not use localized ${key} copy`);
}
assert(plugin.includes("admin_setting('telegram_node_report_locale', 'vi')"),
  'Scheduled node reports do not remember the group locale');
assert(plugin.includes("admin_setting('telegram_database_backup_locale', 'vi')"),
  'Scheduled database backups do not remember the destination locale');
assert(plugin.includes('$user->locale = $this->canonicalUserLocale($this->localeForMessage($msg))'),
  'Telegram reseller accounts can still persist non-canonical locale identifiers');
for (const canonicalLocale of ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  assert(plugin.includes(`'${canonicalLocale}'`), `Telegram canonical user-locale map is missing ${canonicalLocale}`);
}
assert(plugin.includes(`if ($language === 'fa') $value = "\\u{2068}" . $value . "\\u{2069}";`),
  'Persian dynamic values are not protected with Unicode bidi isolates');
assert(!plugin.includes("foreach (['vi-VN', 'en-US', 'zh-CN'] as $locale)"),
  'Telegram reply buttons are still limited to the old three-locale list');
assert(!plugin.includes("'zh' => sprintf"), 'Payment notifications still contain a hardcoded Chinese branch');
assert(!plugin.includes("'vi' => \"📮"), 'Ticket notifications still contain hardcoded locale branches');

console.log(`Telegram bot covers ${expectedLocales.length} locales, ${Object.keys(reference.messages).length} messages, ${Object.keys(reference.commands).length} commands and ${Object.keys(reference.periods).length} billing periods with placeholder parity.`);
