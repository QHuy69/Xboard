const assert = require('assert');
const fs = require('fs');

const controller = fs.readFileSync('app/Http/Controllers/ResourcePortalController.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');
const manage = fs.readFileSync('resources/views/resources/manage.blade.php', 'utf8');
const portal = fs.readFileSync('resources/views/resources/portal.blade.php', 'utf8');

assert(controller.includes("'apps' => 'present|array|max:30'"), 'admin catalog must allow the built-in multi-client list');
assert(controller.includes("'client_catalog_version' => self::CLIENT_CATALOG_VERSION"), 'catalog upgrades must be persisted after an admin save');
assert(routes.includes("Route::get('/download/{platform}/{fingerprint?}', [ResourcePortalController::class, 'download'])"), 'platform download route is missing');
assert(controller.includes("filter_var($item['download_url'], FILTER_VALIDATE_URL)"), 'redirects must accept only valid configured URLs');
assert(controller.includes("public function download(string $platform, ?string $fingerprint = null)"), 'download route must support per-app fingerprints');
assert(controller.includes("redirect()->away($app['download_url']"), 'downloads must redirect to the configured direct binary without proxying it through PHP');
assert(controller.includes("filter_var(($app['download_url'] ?? ''), FILTER_VALIDATE_URL) !== false"), 'blank or invalid download URLs must not render broken cards');
assert(!portal.includes('target="_blank"'), 'resource downloads should stay in the current tab and start the file download');
assert(manage.includes('Liên kết tải xuống trực tiếp') && manage.includes('không dùng trang giới thiệu'), 'admin UI must explain the direct-file URL requirement');

for (const url of [
  'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-Windows-Setup-x64.exe',
  'https://github.com/clash-verge-rev/clash-verge-rev/releases/download/v2.5.2/Clash.Verge_2.5.2_x64-setup.exe',
  'https://github.com/2dust/v2rayN/releases/download/7.24.9/v2rayN-windows-64-desktop.zip',
  'https://github.com/hiddify/hiddify-app/releases/download/v4.1.1/Hiddify-Android-universal.apk',
  'https://github.com/2dust/v2rayNG/releases/download/2.2.6/v2rayNG_2.2.6_arm64-v8a.apk',
  'https://github.com/MatsuriDayo/NekoBoxForAndroid/releases/download/1.4.2/NekoBox-1.4.2-arm64-v8a.apk',
  'https://github.com/shadowsocks/shadowsocks-windows/releases/download/4.4.1.0/Shadowsocks-4.4.1.0.zip',
  'https://github.com/shadowsocks/shadowsocks-iOS/releases/download/2.6.3/ShadowsocksX-2.6.3.dmg',
  'https://github.com/shadowsocks/shadowsocks-android/releases/download/v5.3.5-nightly/shadowsocks-5.3.5-nightly.apk',
  'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFW-1.14.0-x64.exe',
  'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFM-1.14.0-Universal.pkg',
  'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFL-1.14.0-amd64.deb',
  'https://github.com/SagerNet/sing-box/releases/download/v1.14.0/SFA-1.14.0-universal.apk',
  'https://s3.amazonaws.com/outline-releases/client/windows/stable/Outline-Client.exe',
  'https://s3.amazonaws.com/outline-releases/client/linux/stable/outline-client_amd64.deb',
  'https://s3.amazonaws.com/outline-releases/client/android/stable/Outline-Client.apk',
]) {
  assert(controller.includes(url), `direct client asset is missing: ${url}`);
}

console.log('Resource portal direct-download catalog audit passed');
