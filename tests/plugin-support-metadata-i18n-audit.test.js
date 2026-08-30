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

const manifestFiles = [
  'plugins-core/Telegram/config.json',
  'plugins-core/Crisp/config.json',
  'plugins-core/Messenger/config.json'
];
const manifests = manifestFiles.map((file) => ({
  file,
  value: JSON.parse(fs.readFileSync(file, 'utf8'))
}));

function isLocalizable(value) {
  return typeof value === 'string'
    && value.trim() !== ''
    && !/^(?:x{8}-x{4}-x{4}-x{4}-x{12}|facebook\.page\.name|\d+\s*MB)$/i.test(value.trim());
}

const metadataKeys = new Set();
for (const { file, value: manifest } of manifests) {
  assert.strictEqual(manifest.type || 'feature', 'feature', `${file} is not a feature plugin`);
  for (const value of [manifest.name, manifest.description]) {
    if (isLocalizable(value)) metadataKeys.add(value);
  }
  for (const field of Object.values(manifest.config || {})) {
    for (const property of ['label', 'placeholder', 'description']) {
      if (isLocalizable(field[property])) metadataKeys.add(field[property]);
    }
    for (const option of field.options || []) {
      if (isLocalizable(option?.label)) metadataKeys.add(option.label);
    }
  }
}
metadataKeys.add('Chat with support on Messenger');

const vietnameseMarks = /[\u0102\u0103\u0110\u0111\u0128\u0129\u0168\u0169\u01A0-\u01B0\u1EA0-\u1EF9]/;
for (const { file, value: manifest } of manifests) {
  const source = fs.readFileSync(file, 'utf8');
  assert(!vietnameseMarks.test(source), `${file} still uses Vietnamese instead of canonical English metadata keys`);
}

for (const [locale, translations] of Object.entries(locales)) {
  const missing = [...metadataKeys].filter((key) => !(key in translations));
  assert.deepStrictEqual(missing, [], `${locale} is missing support-plugin metadata: ${JSON.stringify(missing)}`);

  if (locale !== 'en-US') {
    const fallback = [...metadataKeys].filter((key) => translations[key] === locales['en-US'][key]);
    assert.deepStrictEqual(fallback, [], `${locale} falls back to English support-plugin metadata: ${JSON.stringify(fallback)}`);
  }

  if (locale !== 'vi-VN') {
    const leaks = [...metadataKeys].filter((key) => vietnameseMarks.test(String(translations[key])));
    assert.deepStrictEqual(leaks, [], `${locale} contains Vietnamese support-plugin copy: ${JSON.stringify(leaks)}`);
  }
}

const controller = fs.readFileSync('app/Http/Controllers/V2/Admin/PluginController.php', 'utf8');
assert(/'name'\s*=>\s*__\(/.test(controller), 'PluginController does not translate plugin names');
assert(/'description'\s*=>\s*__\(/.test(controller), 'PluginController does not translate plugin descriptions');
assert(controller.includes("'config' => $pluginConfig"), 'PluginController does not return translated plugin configuration metadata');

const configService = fs.readFileSync('app/Services/Plugin/PluginConfigService.php', 'utf8');
for (const needle of [
  "'label' => $this->translateMetadata(",
  "'placeholder' => $this->translateMetadata(",
  "'description' => $this->translateMetadata(",
  "'options' => $this->translateOptions("
]) {
  assert(configService.includes(needle), `PluginConfigService is missing translated metadata path: ${needle}`);
}

const dashboard = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
for (const needle of [
  "->whereIn('code', ['crisp', 'messenger'])",
  "$crispInstalled && !(bool) $supportPluginStates->get('crisp')",
  "$messengerInstalled && !(bool) $supportPluginStates->get('messenger')",
  "aria-label=\"{{ __('Chat with support on Messenger') }}\"",
  "title=\"{{ __('Chat with support on Messenger') }}\"",
  "dir=\"{{ app()->getLocale() === 'fa-IR' ? 'rtl' : 'ltr' }}\"",
  "aria-label=\"{{ __('Donation account information') }}\"",
  "aria-label=\"{{ __('Language selector') }}\"",
  "aria-label=\"{{ __('Choose language') }}\""
]) {
  assert(dashboard.includes(needle), `Support widgets are missing lifecycle/i18n behavior: ${needle}`);
}
assert(/\$crispFallback\s*=\s*\$crispInstalled\s*\?\s*''\s*:\s*\(string\) admin_setting/s.test(dashboard),
  'Crisp legacy fallback is not restricted to installations without a plugin record');
assert(/\$messengerFallback\s*=\s*\$messengerInstalled\s*\?\s*''\s*:\s*\(string\) admin_setting/s.test(dashboard),
  'Messenger legacy fallback is not restricted to installations without a plugin record');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const messengerRule = css.match(/\.luck-messenger-support\s*\{([\s\S]*?)\}/)?.[1] || '';
assert(messengerRule.includes('inset-inline-end:'), 'Messenger support button does not use a logical inline position');
assert(!/(?:^|[;\s])right\s*:/.test(messengerRule), 'Messenger support button still hard-codes an LTR-only right position');
assert(messengerRule.includes('unicode-bidi: isolate'), 'Messenger support button is not isolated for RTL rendering');

const accessibilityKeys = ['Donation account information', 'Language selector', 'Choose language'];
for (const [locale, translations] of Object.entries(locales)) {
  const missing = accessibilityKeys.filter((key) => !(key in translations));
  assert.deepStrictEqual(missing, [], `${locale} is missing Luck accessibility copy: ${JSON.stringify(missing)}`);
  if (locale !== 'en-US') {
    const fallback = accessibilityKeys.filter((key) => translations[key] === locales['en-US'][key]);
    assert.deepStrictEqual(fallback, [], `${locale} falls back to English accessibility copy: ${JSON.stringify(fallback)}`);
  }
}

const adminPatch = fs.readFileSync('scripts/patch-admin-user-locale.js', 'utf8');
const adminBundle = fs.readFileSync('public/assets/admin/assets/index-CEIYH7i8.js', 'utf8');
for (const canonicalMetadata of [
  'Crisp Website ID',
  'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
  'UUID Website ID from Crisp settings. Leave blank to keep Crisp Chat disabled.',
  'Facebook Page username',
  'facebook.page.name',
  'Enter the Page username from the end of its m.me link, not the full URL.'
]) {
  assert(adminPatch.includes(canonicalMetadata), `Admin patch is missing canonical plugin metadata: ${canonicalMetadata}`);
  assert(adminBundle.includes(canonicalMetadata), `Admin bundle is missing canonical plugin metadata: ${canonicalMetadata}`);
}
for (const legacyVietnamese of [
  'UUID trang web Crisp',
  'Để trống nếu không dùng Crisp.',
  'Tên người dùng Messenger',
  'Ví dụ: zaoguang.support',
  'Tên sau m.me/, không nhập toàn bộ URL.'
]) {
  assert(!adminPatch.includes(legacyVietnamese), `Admin patch still hard-codes Vietnamese support metadata: ${legacyVietnamese}`);
  assert(!adminBundle.includes(legacyVietnamese), `Admin bundle still hard-codes Vietnamese support metadata: ${legacyVietnamese}`);
}

console.log(`Support plugin manifests expose ${metadataKeys.size} canonical keys translated across ${Object.keys(locales).length} locales, with disabled-plugin and RTL-safe widget guards.`);
