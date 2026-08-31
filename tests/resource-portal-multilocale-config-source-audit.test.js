const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const controller = fs.readFileSync('app/Http/Controllers/ResourcePortalController.php', 'utf8');
const manage = fs.readFileSync('resources/views/resources/manage.blade.php', 'utf8');
const portal = fs.readFileSync('resources/views/resources/portal.blade.php', 'utf8');
const locales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

assert(
  controller.includes("private const PORTAL_LOCALES = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']"),
  'Resources must use one exact eight-locale allowlist'
);
assert(controller.includes("'locales' => $localizedPage"), 'page title/subtitle/notice must be stored by locale');
assert(controller.includes("'translations' => $translations"), 'each app must store localized name/description');
assert(controller.includes("'title' => $localizedPage['vi-VN']['title']"), 'legacy page fields must remain as vi-VN mirrors');
assert(controller.includes("'name' => $translations['vi-VN']['name']"), 'legacy app fields must remain as vi-VN mirrors');

for (const locale of locales) {
  assert(controller.includes(`$rules["locales.{$locale}.title"]`) || controller.includes('foreach (self::PORTAL_LOCALES as $locale)'), 'localized page input must be validated');
  assert(manage.includes(`@foreach ($portalLocales as $localeCode => $localeName)`), 'locale selector must render server-approved options');
}
for (const sharedField of ['platform', 'version', 'download_url', 'enabled', 'sort']) {
  assert(controller.includes(`'${sharedField}' =>`), `shared app field ${sharedField} must remain outside translations`);
}

assert(controller.includes("$storedLocales = is_array($stored['locales'] ?? null) ? $stored['locales'] : []"), 'old config without locales must be accepted');
assert(controller.includes("$storedTranslations = is_array($app['translations'] ?? null) ? $app['translations'] : []"), 'old apps without translations must be accepted');
assert(controller.includes("if ($legacyPage['title'] === '')"), 'a malformed empty legacy title must fall back safely');
assert(controller.includes("if ($legacyName === '')"), 'a malformed empty legacy app name must fall back safely');
assert(controller.includes("$fallback = $legacyPage[$field] === $viCopy[$field]"), 'old built-in Vietnamese page defaults must localize automatically');
assert(controller.includes("$fallbackName = $legacyName === $viDefaultName ? $localeDefaultName : $legacyName"), 'old built-in app names must localize automatically');
assert(controller.includes("$fallbackDescription = $legacyDescription === $viDefaultDescription"), 'old built-in app descriptions must localize automatically');
assert(controller.includes("$value = $viValue === $viCopy[$field] ? $copy[$field] : $viValue"), 'missing locale page content must safely fall back without destroying custom copy');
assert(controller.includes("?? $app['translations']['vi-VN']"), 'public app copy must safely fall back to vi-VN');
assert(controller.includes("?? ['name' => $app['name'], 'description' => $app['description']]"), 'public app copy must retain the legacy fallback');
assert(controller.includes("foreach ($this->defaultEditableApps() as $defaultApp)"), 'old lists must be backfilled with locale-aware OS entries');

assert(manage.includes('id="content-locale"'), 'management page needs a clear locale selector');
assert(manage.includes('data-i18n-key="name"') && manage.includes('data-i18n-key="description"'), 'app name and description must switch with locale');
assert(manage.includes('data-key="platform"') && manage.includes('data-key="download_url"'), 'platform and URL must remain shared inputs');
assert(manage.includes('syncLocalizedPage(); syncApps(); currentLocale = localeSelect.value'), 'locale switching must save pending edits before rendering another locale');
assert(manage.includes('locales:localizedPage'), 'save payload must include all page translations');
assert(manage.includes("app.translations['vi-VN']"), 'save payload must retain a legacy vi-VN app mirror');
assert(manage.includes('Liên kết tải, phiên bản, trạng thái và thứ tự được dùng chung'), 'the editor must explain which values are shared');
assert(portal.includes('<html lang="{{ $locale }}" dir="{{ $direction }}">'), 'public Resources must retain selected locale and direction');
assert(portal.includes('@media(max-width:360px)') && portal.includes('@media(prefers-reduced-motion:reduce)'),
  'Resources portal is missing narrow-foldable or reduced-motion layout guards');

const scriptMatch = manage.match(/<script>([\s\S]*?)<\/script>/);
assert(scriptMatch, 'Resources management script is missing');
const executableScript = scriptMatch[1]
  .replace("@json('/api/v2/' . $securePath . '/resource-portal')", JSON.stringify('/api/v2/admin/resource-portal'))
  .replace('@json($portalLocales)', JSON.stringify(Object.fromEntries(locales.map((locale) => [locale, locale]))))
  .replace('@json($newAppTranslations)', JSON.stringify(Object.fromEntries(locales.map((locale) => [locale, { name: `App ${locale}`, description: '' }]))));
assert.doesNotThrow(() => new vm.Script(executableScript), 'Resources multilingual management JavaScript must remain syntactically valid');

console.log('Resources eight-locale editable content, backward compatibility and shared app metadata verified.');
