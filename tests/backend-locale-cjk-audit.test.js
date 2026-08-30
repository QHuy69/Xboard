const assert = require('assert');
const fs = require('fs');

const localeFiles = {
  'en-US': 'resources/lang/en-US.json',
  'vi-VN': 'resources/lang/vi-VN.json',
  'zh-CN': 'resources/lang/zh-CN.json',
  'zh-TW': 'resources/lang/zh-TW.json',
  'ja-JP': 'resources/lang/ja-JP.json',
  'ko-KR': 'resources/lang/ko-KR.json',
  'fa-IR': 'resources/lang/fa-IR.json',
  'ru-RU': 'resources/lang/ru-RU.json'
};
const locales = Object.fromEntries(Object.entries(localeFiles).map(([locale, file]) => [
  locale,
  JSON.parse(fs.readFileSync(file, 'utf8'))
]));
const english = locales['en-US'];
const englishKeys = Object.keys(english);

const changedBackendSources = [
  'app/Http/Controllers/V1/Guest/PaymentController.php',
  'app/Http/Controllers/V2/Admin/PaymentController.php',
  'app/Http/Controllers/V2/Admin/PluginController.php',
  'app/Services/PaymentService.php',
  'app/Services/Plugin/PluginConfigService.php',
  'app/Services/Plugin/PluginManager.php',
  'plugins-core/CoinPayments/Plugin.php'
];
const changedBackendKeys = new Set();
const literalTranslationPattern = /__\(\s*(['"])(.*?)\1/g;
for (const file of changedBackendSources) {
  const source = fs.readFileSync(file, 'utf8');
  for (const match of source.matchAll(literalTranslationPattern)) {
    changedBackendKeys.add(match[2].replace(/\\'/g, "'").replace(/\\"/g, '"'));
  }
}

// Plugin form metadata is translated centrally by PluginConfigService, so
// canonical manifest strings are translation keys even though JSON cannot call
// __() directly. Opaque example values and unit-only labels are intentionally
// locale-neutral rather than prose that should enter the translation catalog.
function isLocalizablePluginMetadata(value) {
  return typeof value === 'string'
    && value.trim() !== ''
    && !/^(?:CoinPayments|x{8}-x{4}-x{4}-x{4}-x{12}|facebook\.page\.name|\d+\s*MB)$/i.test(value.trim());
}

for (const file of [
  'plugins-core/CoinPayments/config.json',
  'plugins-core/Telegram/config.json',
  'plugins-core/Crisp/config.json',
  'plugins-core/Messenger/config.json'
]) {
  const manifest = JSON.parse(fs.readFileSync(file, 'utf8'));
  for (const value of [manifest.name, manifest.description]) {
    if (isLocalizablePluginMetadata(value)) changedBackendKeys.add(value);
  }
  for (const field of Object.values(manifest.config || {})) {
    for (const property of ['label', 'placeholder', 'description']) {
      if (isLocalizablePluginMetadata(field[property])) changedBackendKeys.add(field[property]);
    }
    for (const option of field.options || []) {
      if (isLocalizablePluginMetadata(option?.label)) changedBackendKeys.add(option.label);
    }
  }
}

for (const [locale, translations] of Object.entries(locales)) {
  const missingChangedBackendKeys = [...changedBackendKeys].filter((key) => !(key in translations));
  assert.deepStrictEqual(missingChangedBackendKeys, [],
    `${locale} is missing changed-backend translation keys: ${JSON.stringify(missingChangedBackendKeys)}`);
}

function placeholders(value) {
  return [...String(value).matchAll(/:[A-Za-z_][A-Za-z0-9_]*|\{\{[^}]+\}\}|\{[A-Za-z_][^}]*\}/g)]
    .map((match) => match[0])
    .sort();
}

for (const [locale, translations] of Object.entries(locales)) {
  const missing = englishKeys.filter((key) => !(key in translations));
  assert.deepStrictEqual(missing, [],
    `${locale} backend locale is missing ${missing.length} keys: ${JSON.stringify(missing)}`);

  const placeholderMismatches = englishKeys.filter((key) =>
    placeholders(english[key]).join('\0') !== placeholders(translations[key]).join('\0')
  );
  assert.deepStrictEqual(placeholderMismatches, [],
    `${locale} backend locale changed interpolation placeholders: ${JSON.stringify(placeholderMismatches)}`);
}

for (const locale of ['en-US', 'vi-VN', 'ko-KR', 'fa-IR', 'ru-RU']) {
  const leaks = Object.entries(locales[locale]).filter(([, value]) =>
    typeof value === 'string' && /[\u3400-\u9fff]/.test(value)
  );
  assert.deepStrictEqual(leaks, [],
    `${locale} backend locale contains source-language CJK fragments: ${JSON.stringify(leaks)}`);
}

const criticalKeys = [
  'Incorrect email or password',
  'Order does not exist',
  'Ticket reply failed',
  'Gift card code is required',
  'Payment method does not exist or is not enabled.',
  'CoinPayments webhook authentication failed.',
  'Leave blank to keep the current saved value.'
];
for (const locale of ['vi-VN', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  const untranslated = criticalKeys.filter((key) => locales[locale][key] === english[key]);
  assert.deepStrictEqual(untranslated, [],
    `${locale} falls back to English in a critical flow: ${JSON.stringify(untranslated)}`);
}

assert(/[\u3040-\u30ff\u3400-\u9fff]/.test(locales['ja-JP']['Incorrect email or password']),
  'Japanese critical authentication copy is not localized');
assert(/[\uac00-\ud7af]/.test(locales['ko-KR']['Incorrect email or password']),
  'Korean critical authentication copy is not localized');
assert(/[\u0600-\u06ff]/.test(locales['fa-IR']['Incorrect email or password']),
  'Persian critical authentication copy is not localized');
assert(/[\u0400-\u04ff]/.test(locales['ru-RU']['Incorrect email or password']),
  'Russian critical authentication copy is not localized');

console.log(`All ${Object.keys(localeFiles).length} backend locales cover ${englishKeys.length} keys and ${changedBackendKeys.size} changed-source literals with intact placeholders and localized P0 flows.`);
