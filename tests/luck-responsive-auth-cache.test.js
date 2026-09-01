const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');
const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const ciSmoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

const languageScript = template.match(/<div class="luck-language-picker"[\s\S]*?<\/div>\s*<script>([\s\S]*?)<\/script>/);
assert(languageScript, 'language picker runtime script is missing');
assert.doesNotThrow(() => new vm.Script(languageScript[1]), 'language picker runtime must remain valid JavaScript');

assert(!template.includes('maximum-scale=1'), 'pinch zoom must not be disabled');
assert(!template.includes('user-scalable=no'), 'user zoom must remain available');
assert(template.includes('luck-overrides.css?v=29'), 'responsive CSS needs a fresh cache key');
assert(template.includes('BBbuoBq5-fresh.js?v=65'), 'entry imports need a fresh cache key');
assert(template.includes('i18n-v18.js?v=61'), 'manual language switching needs a fresh cache key');
assert(template.includes('id="luck-overrides-stylesheet"'), 'responsive stylesheet needs a stable runtime handle');
assert(template.includes("new MutationObserver(placeOverridesLast).observe(document.head, { childList: true })"), 'late route styles must not override the responsive release sheet');

assert(!/\[role="dialog"\]\s*>\s*\*\s*\{/.test(css), 'dialog children must not become nested scroll panes');
assert.match(
  css,
  /@media \(max-width:\s*430px\)[\s\S]*?\.email-verify-wrapper[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  'verification input and action must stack before either can collapse'
);
assert.match(
  css,
  /\.email-code-input input[\s\S]*?min-width:\s*6ch\s*!important/,
  'a six-digit verification code must always have a visible input area'
);
assert.match(
  css,
  /@media \(max-width:\s*360px\)[\s\S]*?\.unified-container \.prefix-input[\s\S]*?min-width:\s*70px\s*!important/,
  'narrow foldables must retain a usable email local-part field'
);
assert.match(
  css,
  /@media \(max-height:\s*760px\)[\s\S]*?padding-top:\s*max\(58px/,
  'short landscape auth pages must reserve the language-picker row'
);
assert(template.includes("firstVisible('.mobile-header')"), 'mobile language picker must use the real header');
assert(template.includes("firstVisible('.header-content')"), 'tablet language picker must use a dedicated header row');
assert(template.includes("firstVisible('.header-actions')"), 'desktop language picker must use the real header');
assert(template.includes('luck-language-picker--mobile'), 'mobile language placement class is missing');
assert(template.includes('luck-language-picker--tablet'), 'tablet language placement class is missing');
assert(template.includes('luck_locale_manual=1'), 'language fallback must preserve an explicit choice');
assert(template.includes("document.documentElement.dir = normalized.indexOf('fa') === 0 ? 'rtl' : 'ltr'"), 'Persian locale must switch the document to RTL');
assert(template.includes('return null;'), 'language picker must not move into a hidden duplicate header');
assert.match(css, /html\[dir="rtl"\] \.luck-language-picker[\s\S]*?left:\s*max\(14px/, 'RTL auth picker must use the left safe area');
assert.match(css, /html\[dir="rtl"\] :where\([\s\S]*?input\[type="email"\][\s\S]*?direction:\s*ltr/, 'email and protocol-like values must remain LTR in Persian');
assert.match(css, /html:is\(\[lang\^="zh"\], \[lang\^="ja"\], \[lang\^="ko"\]\)[\s\S]*?word-break:\s*normal/, 'CJK labels need locale-safe line breaking');
assert.match(css, /\.header-content\.luck-language-host--tablet[\s\S]*?flex-wrap:\s*wrap/, '769-1180px headers need a second row for language controls');

for (const locale of ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  assert(template.includes(`value="${locale}"`), `language picker is missing ${locale}`);
}

assert(routes.includes('LuckThemeAssetPatcher::rewriteNodeAssetImport($assetContents)'), 'entry must use the idempotent node import rewrite');
assert(routes.includes('LuckThemeAssetPatcher::nodeAccessAssetName($runtimeFile)'), 'node output must use the normalized physical filename');
assert(patcher.includes("'-access-v2.js'"), 'node patch needs a new physical cache-busted suffix');
assert(!routes.includes("preg_replace('/\\.js$/', '-access.js'"), 'legacy suffix appending can create access-access files');
for (const [name, source] of [['published-image smoke', ciSmoke], ['production deploy gate', deploy]]) {
  assert(source.includes('node_route_asset'), `${name} does not resolve the lazy node-route module`);
  assert(source.includes('./oPGsis9D*-access-v2.js'), `${name} does not require the normalized node module`);
  assert(source.includes('${node_route_asset#./}'), `${name} does not fetch the referenced node module`);
}
assert(
  patcher.includes('profileStatus === 401 || profileStatus === 403')
    && patcher.includes('if (isAuthFailure)')
    && patcher.includes('logout();'),
  'expired Xboard tokens (403) must be cleared instead of trapping the UI in a profile/network-error loop'
);
assert(
  patcher.includes('backendConfig.value && backendConfig.value.is_invite_force && !formData.inviteCode.trim()')
    && patcher.includes('placeholder: backendConfig.value && backendConfig.value.is_invite_force'),
  'register validation and rendering must tolerate the backend config still loading'
);

// Conservative source-level geometry model. Chromium can reserve about 8px
// for a classic vertical scrollbar, so model that loss before checking the
// usable form width at every phone/foldable size in the release matrix.
const phoneViewports = [
  [280, 653], [320, 568], [344, 882], [360, 800], [375, 812],
  [384, 832], [385, 854], [390, 844], [393, 873], [412, 915],
  [414, 736], [414, 896], [430, 932], [568, 320], [667, 375],
  [844, 390], [915, 412]
];

for (const [width, height] of phoneViewports) {
  const portraitWidth = Math.min(width, height);
  const effectiveWidth = portraitWidth - 8;
  const containerPadding = portraitWidth <= 360 ? 8 : 16;
  const contentPadding = portraitWidth <= 360
    ? 12
    : Math.max(20, Math.min(portraitWidth * 0.06, 32));
  const formWidth = effectiveWidth - (2 * containerPadding) - (2 * contentPadding) - 2;
  assert(formWidth >= 190, `${width}x${height} leaves only ${formWidth}px for the auth form`);
  if (portraitWidth <= 430) {
    const stackedCodeInputWidth = formWidth - 34;
    assert(stackedCodeInputWidth >= 156, `${width}x${height} can collapse the verification input`);
  }
}

const largerViewports = [
  [600, 960], [601, 962], [601, 1007], [768, 1024], [810, 1080], [820, 1180],
  [1024, 768], [1180, 820], [1280, 720], [1280, 1200], [1366, 768], [1366, 1366],
  [1440, 900], [1512, 982], [1536, 864], [1680, 1050], [1920, 1080],
  [2560, 1440], [3840, 2160], [1440, 2560], [2160, 3840], [2560, 1080],
  [3440, 1440], [5120, 1440], [2560, 1600], [3456, 2234], [1728, 1117], [3072, 1728]
];
assert(largerViewports.every(([width, height]) => width > 0 && height > 0), 'responsive release matrix is invalid');

console.log(`Verified auth geometry/cache guards across ${phoneViewports.length + largerViewports.length} viewport profiles.`);
