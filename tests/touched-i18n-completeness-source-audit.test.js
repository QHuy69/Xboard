const assert = require('assert');
const fs = require('fs');

const localeFiles = [
  'en-US.json', 'vi-VN.json', 'zh-CN.json', 'zh-TW.json',
  'ja-JP.json', 'ko-KR.json', 'fa-IR.json', 'ru-RU.json'
];
const locales = Object.fromEntries(localeFiles.map((file) => [
  file,
  JSON.parse(fs.readFileSync(`resources/lang/${file}`, 'utf8'))
]));

const baselineKeys = Object.keys(locales['en-US.json']).sort();
for (const [file, catalog] of Object.entries(locales)) {
  assert.deepStrictEqual(
    Object.keys(catalog).sort(),
    baselineKeys,
    `${file} must have exact backend locale-key parity`
  );
}

const backendSources = [
  'app/Http/Controllers/V2/Admin/UserController.php',
  'app/Http/Requests/Admin/UserUpdate.php',
  'app/Services/TelegramResellerService.php'
];
const translatedKeys = new Set();
for (const file of backendSources) {
  const source = fs.readFileSync(file, 'utf8');
  for (const match of source.matchAll(/__\(\s*(['"])(.*?)\1/g)) translatedKeys.add(match[2]);
}
for (const key of translatedKeys) {
  const expectedPlaceholders = [...key.matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)]
    .map((match) => match[1])
    .sort();
  for (const [file, catalog] of Object.entries(locales)) {
    assert(Object.prototype.hasOwnProperty.call(catalog, key), `${file} is missing translated backend key ${JSON.stringify(key)}`);
    const actualPlaceholders = [...String(catalog[key]).matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)]
      .map((match) => match[1])
      .sort();
    assert.deepStrictEqual(
      actualPlaceholders,
      expectedPlaceholders,
      `${file} placeholder mismatch for backend key ${JSON.stringify(key)}`
    );
  }
}

const userController = fs.readFileSync('app/Http/Controllers/V2/Admin/UserController.php', 'utf8');
const userUpdate = fs.readFileSync('app/Http/Requests/Admin/UserUpdate.php', 'utf8');
for (const leaked of [
  'Người dùng không tồn tại', 'Email này đã được sử dụng', 'Gói đăng ký không tồn tại',
  'Lưu thay đổi thất bại', 'user_ids不能为空', '生成失败', '批量生成成功',
  '长期有效', '无订阅', '处理失败', '用户ID不能为空', '删除失败'
]) {
  assert(!userController.includes(leaked), `admin user responses must not leak hardcoded source copy: ${leaked}`);
}
assert(!/[À-ỹ]/u.test(userUpdate), 'admin user validation must not hardcode Vietnamese responses');

const resourceController = fs.readFileSync('app/Http/Controllers/ResourcePortalController.php', 'utf8');
const resourceView = fs.readFileSync('resources/views/resources/portal.blade.php', 'utf8');
const resourceLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
for (const [localeIndex, locale] of resourceLocales.entries()) {
  const localeStart = resourceController.indexOf(`'${locale}' => [`);
  assert(localeStart >= 0, `Resources copy is missing ${locale}`);
  const nextLocale = localeIndex + 1 < resourceLocales.length
    ? resourceController.indexOf(`'${resourceLocales[localeIndex + 1]}' => [`, localeStart + 1)
    : resourceController.indexOf('        ];', localeStart + 1);
  const block = resourceController.slice(localeStart, nextLocale < 0 ? undefined : nextLocale);
  for (const key of ['title', 'subtitle', 'notice', 'app_default', 'other', 'app_names', 'app_descriptions']) {
    assert(block.includes(`'${key}' =>`), `Resources ${locale} copy is missing ${key}`);
  }
  for (const platform of ['windows', 'macos', 'linux', 'android', 'ios']) {
    assert(block.includes(`'${platform}' =>`), `Resources ${locale} copy is missing ${platform}`);
  }
}
assert(resourceController.includes("$storedLocales = is_array($stored['locales'] ?? null) ? $stored['locales'] : []"), 'saved Resources content must support locale-aware configuration');
assert(resourceController.includes("$legacyPage[$field] === $viCopy[$field]"), 'saved Vietnamese Resources defaults need locale migration at render time');
assert(resourceController.includes("foreach ($this->defaultEditableApps() as $defaultApp)"), 'missing-OS backfill must carry every locale default');
assert(resourceController.includes("$translation = $app['translations'][$locale]"), 'public Resources apps must select the requested translation');
assert(resourceView.includes("'other' => $copy['other']"), 'custom Resources platform label must be localized');

const telegramGuest = fs.readFileSync('app/Http/Controllers/V1/Guest/TelegramController.php', 'utf8');
for (const localeMarker of ['zh-tw', "'vi' =>", "'zh' =>", "'ja' =>", "'ko' =>", "'fa' =>", "'ru' =>"]) {
  assert(telegramGuest.includes(localeMarker), `Telegram handler fallback is missing ${localeMarker}`);
}
assert(telegramGuest.includes('fallbackErrorForLocale'), 'Telegram handler errors must use the 8-locale fallback');

console.log(`Touched localization audit passed for ${localeFiles.length} locales and ${translatedKeys.size} backend keys.`);
