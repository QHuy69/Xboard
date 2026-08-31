const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const dashboard = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const css = fs.readFileSync('luck-overrides.css', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/ResourcePortalController.php', 'utf8');
const portal = fs.readFileSync('resources/views/resources/portal.blade.php', 'utf8');
const ciSmoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

const platformAnchor = dashboard.indexOf("var PLATFORM_ORDER = ['windows', 'macos', 'linux', 'android', 'ios']");
const platformScriptStart = dashboard.lastIndexOf('<script>', platformAnchor) + '<script>'.length;
const platformScriptEnd = dashboard.indexOf('</script>', platformAnchor);
assert(platformAnchor >= 0 && platformScriptStart >= '<script>'.length && platformScriptEnd > platformScriptStart, 'Luck platform enhancement script is missing');
const platformScript = dashboard.slice(platformScriptStart, platformScriptEnd)
  .replace('@json($luckDonatePlanIds)', '[]');
assert.doesNotThrow(() => new vm.Script(platformScript), 'Luck five-platform browser enhancement must remain valid JavaScript');

const downloadAnchor = dashboard.match(/<a id="luck-app-download"[\s\S]*?<\/a>/);
assert(downloadAnchor, 'Luck dashboard resources CTA is missing');
assert(!/\btarget="_blank"/.test(downloadAnchor[0]), 'Resources must open in the same tab so browser Back returns to the dashboard');
assert.match(downloadAnchor[0], /LUCK_RESOURCES_URL/, 'the CTA must retain the configured Resources origin');

assert(
  dashboard.includes("var PLATFORM_ORDER = ['windows', 'macos', 'linux', 'android', 'ios']"),
  'the dashboard must expose the approved five OS choices in deterministic order'
);
for (const platform of ['macos', 'linux']) {
  assert(
    dashboard.includes("['macos', 'linux'].forEach(function (platform)") &&
      dashboard.includes("cards[platform] = createPlatformCard(platform)"),
    `${platform} must be injected idempotently into the generated Luck platform grid`
  );
}
for (const locale of ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  assert(
    dashboard.includes(`'${locale}': { group:`),
    `OS selector copy is missing ${locale}`
  );
  assert(
    controller.includes(`'${locale}' => [`),
    `Resources shell copy is missing ${locale}`
  );
}

assert(
  dashboard.includes("platformCards.setAttribute('role', 'listbox')") &&
    dashboard.includes("card.setAttribute('role', 'option')") &&
    dashboard.includes("card.setAttribute('aria-selected', selected ? 'true' : 'false')") &&
    dashboard.includes("card.setAttribute('tabindex', selected ? '0' : '-1')"),
  'OS selection needs listbox semantics, explicit selection state, and roving keyboard focus'
);
for (const key of ['Enter', ' ', 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End']) {
  assert(dashboard.includes(`event.key === '${key}'`), `OS keyboard selection is missing ${JSON.stringify(key)}`);
}
assert(
  dashboard.includes("sessionStorage.setItem('luck_download_platform', platform)") &&
    dashboard.includes("sessionStorage.getItem('luck_download_platform')"),
  'the selected OS must survive the Resources round trip within the browser tab'
);

const updateHrefMatch = dashboard.match(/var updateDownloadHref = (function \(platform\) \{[\s\S]*?\n      \});/);
assert(updateHrefMatch, 'deterministic Resources URL builder is missing');
const fakeDownload = {
  attributes: {},
  dataset: {},
  setAttribute(name, value) { this.attributes[name] = String(value); },
  removeAttribute(name) { delete this.attributes[name]; }
};
const updateDownloadHref = vm.runInNewContext(`(${updateHrefMatch[1]})`, {
  download: fakeDownload,
  PLATFORM_ORDER: ['windows', 'macos', 'linux', 'android', 'ios'],
  resourcesBaseHref: 'https://resources.example.test/catalog?channel=stable#old',
  window: { location: { href: 'https://panel.example.test/dashboard' } },
  platformLocale: 'fa-IR',
  localizedDownloadLabel: 'دانلود برنامه',
  platformName: (platform) => platform === 'macos' ? 'macOS' : platform === 'ios' ? 'iOS' : platform[0].toUpperCase() + platform.slice(1),
  URL
});
updateDownloadHref('linux');
const selectedUrl = new URL(fakeDownload.attributes.href);
assert.strictEqual(selectedUrl.origin, 'https://resources.example.test');
assert.strictEqual(selectedUrl.pathname, '/catalog');
assert.strictEqual(selectedUrl.searchParams.get('channel'), 'stable', 'configured Resources query values must be preserved');
assert.strictEqual(selectedUrl.searchParams.get('platform'), 'linux');
assert.strictEqual(selectedUrl.searchParams.get('lang'), 'fa-IR');
assert.strictEqual(selectedUrl.hash, '#platform-linux');
assert.strictEqual(fakeDownload.attributes.target, undefined, 'the generated CTA must remain same-tab navigation');
assert.strictEqual(fakeDownload.dataset.luckPlatform, 'linux');
const validHref = fakeDownload.attributes.href;
updateDownloadHref('freebsd');
assert.strictEqual(fakeDownload.attributes.href, validHref, 'an unsupported dashboard platform must not alter the Resources URL');

const bindPlatformMatch = dashboard.match(/var bindPlatformCard = (function \(card, platform\) \{[\s\S]*?\n      \});/);
assert(bindPlatformMatch, 'OS mouse and keyboard binding function is missing');
const selections = [];
const scheduled = [];
const bindPlatformCard = vm.runInNewContext(`(${bindPlatformMatch[1]})`, {
  PLATFORM_ORDER: ['windows', 'macos', 'linux', 'android', 'ios'],
  platformName: (platform) => platform,
  selectDownloadPlatform: (platform, focus) => selections.push({ platform, focus }),
  window: { setTimeout: (listener) => scheduled.push(listener) }
});
const listeners = {};
const fakeCard = {
  dataset: {},
  attributes: {},
  description: { textContent: 'Linux computer' },
  querySelector(selector) { return selector === '.platform-desc' ? this.description : null; },
  setAttribute(name, value) { this.attributes[name] = String(value); },
  addEventListener(name, listener) { listeners[name] = listener; },
  click() { listeners.click(); }
};
bindPlatformCard(fakeCard, 'linux');
listeners.click();
assert.deepStrictEqual(selections.pop(), { platform: 'linux', focus: false }, 'mouse click must update the Resources URL synchronously');
scheduled.shift()();
assert.deepStrictEqual(selections.pop(), { platform: 'linux', focus: false }, 'mouse click must preserve its exact OS after Vue updates');
for (const [key, expected] of [
  ['Enter', 'linux'], [' ', 'linux'], ['ArrowRight', 'android'], ['ArrowDown', 'android'],
  ['ArrowLeft', 'macos'], ['ArrowUp', 'macos'], ['Home', 'windows'], ['End', 'ios']
]) {
  let prevented = false;
  listeners.keydown({ key, preventDefault() { prevented = true; } });
  if (key === 'Enter' || key === ' ') {
    assert.strictEqual(selections.pop().platform, expected, `${JSON.stringify(key)} must select synchronously`);
    scheduled.shift()();
  }
  const selection = selections.pop();
  assert.strictEqual(selection.platform, expected, `${JSON.stringify(key)} must select ${expected}`);
  assert.strictEqual(prevented, true, `${JSON.stringify(key)} must prevent page scrolling/default activation`);
}
assert.strictEqual(fakeCard.attributes.role, 'option');
assert.strictEqual(fakeCard.attributes['aria-label'], 'linux: Linux computer');

assert.match(
  css,
  /\.platform-cards\[data-v-3709f5eb\]\s*\{[\s\S]*?grid-template-columns:\s*repeat\(5,\s*minmax\(0,\s*1fr\)\)\s*!important/,
  'wide Luck dashboards must render all five OS cards in one row'
);
for (const breakpoint of ['900', '640', '420']) {
  assert(css.includes(`@container luck-download-section (max-width: ${breakpoint}px)`), `OS grid is missing its ${breakpoint}px container breakpoint`);
}
assert.match(
  css,
  /\.platform-card\[data-luck-platform\][^\{]*:focus-visible\s*\{[\s\S]*?outline:/,
  'keyboard OS selection needs a visible focus indicator'
);
assert.match(
  css,
  /\.platform-card\[data-luck-platform\][\s\S]*?\.platform-desc\s*\{[\s\S]*?word-break:\s*keep-all\s*!important[\s\S]*?writing-mode:\s*horizontal-tb\s*!important/,
  'OS labels must not stack vertically in translated narrow layouts'
);

assert(
  controller.includes('public function index(Request $request)') &&
    controller.includes("$allowedPlatforms = ['windows', 'macos', 'linux', 'android', 'ios']") &&
    controller.includes("$request->query('platform', '')") &&
    controller.includes("if (!in_array($selectedPlatform, $allowedPlatforms, true))") &&
    controller.includes("$selectedPlatform = ''") &&
    controller.includes("$items->where('platform', $selectedPlatform)"),
  'Resources must reject unsupported platforms and filter server-side from the stable platform query parameter'
);
assert(
  controller.includes("admin_setting('linux_download_url', '')") &&
    controller.includes("admin_setting('ios_download_url', '')") &&
    controller.includes("$existingPlatforms = $apps->pluck('platform')->unique()->all()") &&
    controller.includes("if (!in_array($defaultApp['platform'], $existingPlatforms, true))") &&
    controller.includes('$apps->push($defaultApp)'),
  'default and older saved Resources configurations must expose administrator-configurable Linux and iOS slots'
);
assert(
  portal.includes('id="{{ $selectedPlatform ? \'platform-\' . $selectedPlatform : \'apps\' }}"') &&
    portal.includes('data-selected-platform="{{ $selectedPlatform }}"') &&
    portal.includes("target.scrollIntoView({ block: 'start' })") &&
    portal.includes("window.addEventListener('pageshow', reveal)"),
  'filtered Resources results must retain a deterministic anchor across direct load, reload, and browser history'
);
assert(
    portal.includes('<html lang="{{ $locale }}" dir="{{ $direction }}">') &&
    portal.includes("{{ $copy['download'] }}") &&
    portal.includes("$copy['empty_platform']"),
  'Resources selection UI must use the requested locale and RTL direction without mixed hardcoded labels'
);
assert(
  controller.includes("->filter(fn (array $app) => $app['enabled'] && $app['download_url'] !== '')") &&
    controller.includes("->when($selectedPlatform !== '', fn ($items) => $items->where('platform', $selectedPlatform))") &&
    portal.includes("$selectedPlatform ? str_replace(':platform', $platformNames[$selectedPlatform], $copy['empty_platform']) : $copy['empty']"),
  'a selected OS with no configured download must render its localized empty state instead of a blank grid'
);

for (const releaseGate of [ciSmoke, deploy]) {
  assert(releaseGate.includes("var PLATFORM_ORDER = ['windows', 'macos', 'linux', 'android', 'ios'];"), 'release gate is missing the exact five-platform marker');
  assert(releaseGate.includes("target.searchParams.set('platform', platform);"), 'release gate is missing the Resources query handoff marker');
  assert(releaseGate.includes('data-selected-platform="{{ $selectedPlatform }}"'), 'release gate is missing the filtered Resources view marker');
  assert(releaseGate.includes("'empty_platform' =>"), 'release gate is missing the localized zero-download marker');
  assert(releaseGate.includes('for resource_platform in windows macos linux android ios; do'),
    'release gate must exercise every Resources platform over HTTP');
  assert(releaseGate.includes("--header 'Host: resources.zaoguang-vpn.com'"),
    'release gate must exercise the domain-bound Resources route');
  assert(releaseGate.includes('data-selected-platform=\\"${resource_platform}\\"'),
    'release gate must verify the server-rendered selected platform');
  assert(releaseGate.includes('platform=freebsd&lang=en-US') && releaseGate.includes('data-selected-platform=""'),
    'release gate must prove unsupported Resources platforms fail closed');
}

console.log('Luck five-platform dashboard to Resources selection audit passed');
