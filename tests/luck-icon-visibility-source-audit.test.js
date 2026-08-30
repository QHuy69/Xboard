const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const flags = fs.readFileSync('luck-flags.svg', 'utf8');

assert(template.includes('luck-overrides.css?v=20'), 'icon CSS changes need a new browser cache key');

for (const selector of [
  '.menu-icon', '.nav-icon', '.btn-icon', '.input-icon', '.dialog-icon',
  '.platform-icon', '.app-icon-wrapper', '.subscription-icon',
  '.payment-method-icon', '.method-icon', '.stat-icon', '.card-icon-premium'
]) {
  assert(css.includes(selector), `icon visibility contract is missing ${selector}`);
}

assert.match(css, /:where\([\s\S]*?\.platform-icon[\s\S]*?\) :where\(svg, \.n-icon\)[\s\S]*?visibility:\s*visible !important/, 'SVG and Naive icons must stay measurable and visible');
assert.match(css, /\.platform-card\.windows \.platform-icon\s*\{\s*color:\s*#0b66b2 !important/, 'Windows/PC icon needs explicit contrast');
assert.match(css, /\.platform-card\.android \.platform-icon\s*\{\s*color:\s*#137a42 !important/, 'Android icon needs explicit contrast');
assert.match(css, /\.platform-card\.ios \.platform-icon\s*\{\s*color:\s*#334bb4 !important/, 'iOS icon needs explicit contrast');
assert.match(css, /\[data-luck-icon-fallback\]::after\s*\{[\s\S]*?content:\s*attr\(data-luck-icon-fallback\)/, 'failed remote icon images need a deterministic visual fallback');

const hiddenAlert = css.indexOf('.unpaid-order-alert .alert-icon {\n    display: none !important;');
const visibleAlert = css.lastIndexOf('.unpaid-order-alert .alert-icon {');
assert(hiddenAlert >= 0 && visibleAlert > hiddenAlert, 'mobile alert icon must be restored after the legacy hide rule');
assert(css.slice(visibleAlert, visibleAlert + 260).includes('display: inline-grid !important'), 'mobile alert icon must remain visible');

const scripts = [...template.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
const iconRuntime = scripts.find((script) => script.includes('var ICON_IMAGE_SELECTOR'));
assert(iconRuntime, 'runtime icon-image fallback is missing');
assert.doesNotThrow(
  () => new vm.Script(iconRuntime.replace('@json($luckDonatePlanIds)', '[]')),
  'runtime icon fallback must remain valid JavaScript'
);
for (const marker of [
  "'.app-icon-wrapper img.app-icon'",
  "'.subscription-icon img.subscription-logo'",
  "'.payment-method-icon img'",
  "image.addEventListener('error'",
  "image.addEventListener('load'",
  "host.setAttribute('data-luck-icon-fallback'",
  'syncIconVisibility();'
]) {
  assert(iconRuntime.includes(marker), `runtime icon fallback is missing ${marker}`);
}

assert(patcher.includes('/theme/Luck/assets/luck-flags.svg?v=1#${flagAssetCode}'), 'node flags must use the packaged local SVG sprite');
assert(patcher.includes('class: "luck-node-flag-code"'), 'unknown flags need a visible ISO fallback');
assert(!patcher.includes('String.fromCodePoint(...flagCode'), 'node flags must not rely on regional-indicator emoji');
assert(!/(?:href|src)=["']https?:\/\//.test(flags), 'flag sprite must be entirely local');

console.log('Luck user icons have deterministic contrast, sizing, image fallbacks and local node flags.');
