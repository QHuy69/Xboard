const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const service = fs.readFileSync('app/Services/UserDeviceReadService.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/V1/User/UserDeviceController.php', 'utf8');
const resource = fs.readFileSync('app/Http/Resources/UserDeviceResource.php', 'utf8');
const userRoutes = fs.readFileSync('app/Http/Routes/V1/UserRoute.php', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const css = fs.readFileSync('luck-overrides.css', 'utf8');
const runtime = fs.readFileSync('luck-i18n-v18.js', 'utf8');

const panelScriptMarker = "var ENDPOINT = '/api/v1/user/devices/current'";
const panelScriptMarkerIndex = template.indexOf(panelScriptMarker);
const panelScriptStart = template.lastIndexOf('<script>', panelScriptMarkerIndex);
const panelScriptEnd = template.indexOf('</script>', panelScriptMarkerIndex);
assert(panelScriptMarkerIndex > 0 && panelScriptStart >= 0 && panelScriptEnd > panelScriptStart, 'unable to isolate current-IP runtime script');
assert.doesNotThrow(
  () => new vm.Script(template.slice(panelScriptStart + '<script>'.length, panelScriptEnd)),
  'current-IP runtime script has invalid JavaScript syntax'
);

// Privacy and scale: one exact authenticated-user hash, never a Redis scan or
// a request-supplied user id.
assert(service.includes("private const REDIS_PREFIX = 'user_devices:'"), 'device reader uses the wrong Redis namespace');
assert(service.includes('Redis::hgetall(self::REDIS_PREFIX . $userId)'), 'device reader must perform one exact HGETALL');
assert(!/Redis::(?:keys|scan)\s*\(/i.test(service), 'user device endpoint must never scan Redis');
assert(controller.includes('$request->user()->id'), 'device controller is not scoped to the authenticated user');
assert(!controller.includes("input('user_id") && !controller.includes("query('user_id"), 'device controller must not accept a user id');
assert(userRoutes.includes("'middleware' => 'user'"), 'V1 user routes lost authentication middleware');
assert(userRoutes.includes("$router->get('/devices/current', [UserDeviceController::class, 'current'])"), 'authenticated current-device route is missing');
assert(controller.includes("'Cache-Control', 'private, no-store, max-age=0'"), 'IP responses must not be shared or cached');

// Freshness and node visibility: five-minute TTL, malformed/stale IP filtering,
// one current row per IP, and enabled-node metadata only.
assert(service.includes('private const TTL_SECONDS = 300'), 'online IP TTL must stay aligned with DeviceStateService');
assert(service.includes('$ageSeconds > self::TTL_SECONDS'), 'stale online IP fields are not filtered');
assert(service.includes('filter_var($ip, FILTER_VALIDATE_IP)'), 'malformed IP values are not rejected');
assert(service.includes("$latestByIp[$ip]"), 'same IP must be deduplicated while switching nodes');
assert(service.includes("->where('enabled', true)"), 'disabled server nodes must not be exposed');
for (const field of ['ip', 'node_id', 'node_name', 'type', 'last_seen_at', 'age_seconds']) {
  assert(resource.includes(`'${field}'`), `device response is missing ${field}`);
}
assert(controller.includes("'total' => count($current)"), 'device response total is missing');
assert(controller.includes("'current' => UserDeviceResource::collection($current)->resolve($request)"), 'current device rows are missing');

// Stable runtime placement and safe rendering: the panel follows the traffic
// card, survives Vue route remounts, and never interpolates node/IP strings as
// HTML.
for (const source of [
  panelScriptMarker,
  "var PANEL_ID = 'luck-current-ip-panel'",
  "document.querySelector('.traffic-dashboard')",
  "traffic.insertAdjacentElement('afterend', panel)",
  'new MutationObserver(scheduleSync)',
  "credentials: 'same-origin'",
  "cache: 'no-store'",
  "'Authorization': token",
  "ip.dir = 'ltr'",
  "new Intl.RelativeTimeFormat(currentLocale(), { numeric: 'auto' })"
]) {
  assert(template.includes(source), `Luck current-IP runtime is missing: ${source}`);
}
assert(!template.slice(template.indexOf("var ENDPOINT = '/api/v1/user/devices/current'"), template.indexOf('    }());', template.indexOf("var ENDPOINT = '/api/v1/user/devices/current'"))).includes('.innerHTML'), 'device data must be rendered with textContent, not innerHTML');

for (const pattern of [
  /\.luck-current-ip-panel\s*\{[\s\S]*?width:\s*100%[\s\S]*?min-width:\s*0[\s\S]*?container:\s*luck-current-ip\s*\/\s*inline-size/,
  /\.luck-current-ip-address\s*\{[\s\S]*?direction:\s*ltr[\s\S]*?white-space:\s*nowrap[\s\S]*?user-select:\s*all/,
  /@container luck-current-ip \(max-width:\s*720px\)[\s\S]*?\.luck-current-ip-table thead\s*\{[\s\S]*?display:\s*none[\s\S]*?\.luck-current-ip-table td\s*\{[\s\S]*?grid-template-columns/,
  /@container luck-current-ip \(max-width:\s*390px\)[\s\S]*?\.luck-current-ip-table td\s*\{[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  /@media \(max-width:\s*768px\)[\s\S]*?\.luck-current-ip-table thead\s*\{[\s\S]*?display:\s*none[\s\S]*?\.luck-current-ip-table td\s*\{[\s\S]*?grid-template-columns/,
  /@media \(max-width:\s*390px\)[\s\S]*?\.luck-current-ip-table td\s*\{[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  /html\[dir="rtl"\] \.luck-current-ip-table/
]) {
  assert.match(css, pattern, `current-IP responsive/RTL CSS is missing: ${pattern}`);
}
assert(template.includes('luck-overrides.css?v=18'), 'current-IP CSS cache key was not bumped');
assert(template.includes('i18n-v18.js?v=60'), 'current-IP translations cache key was not bumped');

function translator(locale) {
  const sandbox = {
    window: {
      LUCK_SERVER_LANGUAGES: [locale],
      LUCK_DEFAULT_LANGUAGE: locale,
      localStorage: {
        getItem(key) {
          if (key === 'luck_locale') return locale;
          if (key === 'luck_locale_manual') return '1';
          return null;
        },
        setItem() {}
      },
      location: { reload() {} }
    },
    navigator: { languages: [locale], language: locale },
    document: {
      documentElement: { lang: '', classList: { remove() {} } },
      title: '', head: null, body: null,
      cookie: `luck_locale=${locale}; luck_locale_manual=1`,
      addEventListener() {}
    },
    MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
    Intl,
    console: { log() {}, warn() {}, error() {} },
    setTimeout,
    clearTimeout
  };
  vm.runInNewContext(runtime, sandbox);
  return sandbox.window.__LUCK_T__;
}

const localeIds = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
const panelKeys = ['当前使用IP', 'IP地址', '节点', '当前没有活动IP', '正在加载活动IP...', '无法加载活动IP', '最后活动'];
for (const locale of localeIds) {
  const t = translator(locale);
  for (const key of panelKeys) {
    const value = t(key);
    assert(value && (locale === 'zh-CN' || value !== key), `${locale} current-IP label did not translate: ${key}`);
    if (!['zh-CN', 'zh-TW', 'ja-JP'].includes(locale)) {
      assert(!/[\u3400-\u9fff]/.test(value), `${locale} leaked CJK in current-IP label: ${key} => ${value}`);
    }
  }
  for (const sharedKey of ['协议', '刷新']) {
    const value = t(sharedKey);
    assert(value && (locale === 'zh-CN' || value !== sharedKey), `${locale} shared current-IP label did not translate: ${sharedKey}`);
  }
}
assert.strictEqual(translator('vi-VN')('当前使用IP'), 'IP đang sử dụng');
assert.strictEqual(translator('fa-IR')('最后活动'), 'آخرین فعالیت');

console.log('Verified authenticated current-user IP API, stale filtering, responsive table and all 8 locales.');
