const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');

function expect(pattern, message) {
  assert.match(css, pattern, message);
}

expect(
  /\.main-layout\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*2fr\)\s+minmax\(300px,\s*1fr\)/,
  'desktop dashboard columns must have explicit readable minimums'
);
expect(
  /\.left-bottom\[data-v-3709f5eb\][\s\S]*?grid-area:\s*auto\s*!important[\s\S]*?width:\s*100%\s*!important/,
  'the stock left-bottom named area must not create an implicit column that collapses the user card'
);
expect(
  /\.left-column\[data-v-3709f5eb\]\s*\{[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s*!important[\s\S]*?grid-auto-flow:\s*row\s*!important[\s\S]*?grid-auto-columns:\s*minmax\(0,\s*1fr\)\s*!important/,
  'the desktop left column must remain one explicit track instead of recreating a phantom middle column'
);
expect(
  /\.left-column\[data-v-3709f5eb\]\s*>\s*\*\s*\{[\s\S]*?grid-column:\s*1\s*\/\s*-1\s*!important/,
  'the user card and download sections must span the same desktop column'
);
expect(
  /container:\s*luck-user-card\s*\/\s*inline-size/,
  'dashboard card must respond to its real available width, not only the viewport'
);
expect(
  /\.dashboard-content\[data-v-3709f5eb\][\s\S]*?container:\s*luck-dashboard\s*\/\s*inline-size/,
  'dashboard columns must use their real post-sidebar width as a container'
);
expect(
  /@container luck-dashboard \(max-width:\s*920px\)[\s\S]*?\.main-layout\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  'dashboard columns must stack by available content width instead of raw viewport width'
);
expect(
  /@supports not \(container-type:\s*inline-size\)[\s\S]*?@media \(max-width:\s*1500px\)[\s\S]*?\.user-main-info[\s\S]*?@media \(max-width:\s*1180px\)[\s\S]*?\.main-layout/,
  'viewport-only desktop stacking must be limited to legacy browsers without container queries'
);
expect(
  /@container luck-user-card \(max-width:\s*800px\)[\s\S]*?\.user-main-info[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  'profile and balance cards must stack before their text is compressed'
);
expect(
  /\.user-card\[data-v-3709f5eb\][\s\S]*?overflow:\s*hidden\s*!important[\s\S]*?container:\s*luck-user-card/,
  'user card must contain avatar artwork instead of exposing a clipped white crescent'
);
expect(
  /\.user-profile \.user-avatar-enhanced\[data-v-3709f5eb\][\s\S]*?width:\s*88px\s*!important[\s\S]*?height:\s*88px\s*!important[\s\S]*?aspect-ratio:\s*1\s*\/\s*1/,
  'avatar must keep a square, non-shrinking footprint in narrow profile columns'
);
expect(
  /@container luck-user-card \(max-width:\s*520px\)[\s\S]*?\.user-profile\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)[\s\S]*?\.user-subscription-item\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  'a narrow card must stack both profile and subscription label/value rows by container width'
);
expect(
  /\.balance-cards-premium\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*repeat\(3,\s*minmax\(132px,\s*1fr\)\)/,
  'balance cards must share available space without fixed widths or negative margins'
);
expect(
  /\.wallet-card-wide \.ios-card-action\[data-v-3709f5eb\]\s*\{[\s\S]*?display:\s*none\s*!important/,
  'the duplicate unstyled wallet recharge action must be hidden'
);
expect(
  /\.wallet-card-wide \.recharge-btn-float\[data-v-3709f5eb\]\s*\{[\s\S]*?display:\s*inline-flex\s*!important/,
  'the intended floating wallet recharge action must remain visible'
);
expect(
  /\.user-subscription-item \.subscription-label\[data-v-3709f5eb\],[\s\S]*?overflow-wrap:\s*normal[\s\S]*?word-break:\s*keep-all/,
  'subscription labels must wrap by words instead of one character per line'
);
expect(
  /@media \(max-width:\s*390px\)[\s\S]*?\.user-subscription-item\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  '320-390px phones and narrow foldables must stack label/value rows'
);
expect(
  /\.servers-table-container\[data-v-85145c70\][\s\S]*?overflow-x:\s*scroll\s*!important[\s\S]*?scrollbar-gutter:\s*stable/,
  'the node card must keep a visible stable native horizontal scroll owner'
);
expect(
  /\.servers-table-container\[data-v-85145c70\]::-webkit-scrollbar\s*\{[\s\S]*?height:\s*11px/,
  'the node card must expose a visibly sized WebKit horizontal rail'
);
expect(
  /\.servers-table-container\[data-v-85145c70\]::-webkit-scrollbar-thumb\s*\{[\s\S]*?min-width:\s*40px[\s\S]*?background:\s*rgba\(71,\s*85,\s*105,\s*\.72\)/,
  'the node card native scrollbar must have a visible usable thumb'
);
expect(
  /\.compact-table\.desktop-table\[data-v-85145c70\]\s*\{[\s\S]*?width:\s*max\(100%,\s*1200px\)\s*!important[\s\S]*?min-width:\s*1200px\s*!important/,
  'desktop and tablet node tables must retain the width required by scroll-x'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-data-table-wrapper[\s\S]*?overflow-x:\s*hidden\s*!important/,
  'the Naive wrapper must not create a second horizontal scrollbar'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-scrollbar-container[\s\S]*?padding-bottom:\s*0\s*!important/,
  'the hidden inner Naive rail must not reserve duplicate scrollbar space'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-scrollbar-rail--horizontal\s*\{[\s\S]*?display:\s*none\s*!important/,
  'the conditional Naive rail must be hidden so the card remains the only scroll owner'
);
expect(
  /@media \(max-width:\s*768px\)[\s\S]*?\.servers-table-container\[data-v-85145c70\][\s\S]*?overflow-x:\s*hidden\s*!important[\s\S]*?\.compact-table\.desktop-table\[data-v-85145c70\][\s\S]*?min-width:\s*0\s*!important/,
  'mobile node cards must reset the desktop scrolling contract'
);
expect(
  /\.luck-node-flag\s*\{[\s\S]*?place-items:\s*center[\s\S]*?overflow:\s*hidden/,
  'the node-country flag must have a visible, aligned SVG host'
);
expect(/\.luck-node-flag-code\s*\{[\s\S]*?position:\s*absolute[\s\S]*?font-size:\s*6px/, 'node flags need a visible ISO fallback');

for (const viewport of [
  { name: 'foldable cover', width: 280, expected: 390 },
  { name: 'small phone', width: 320, expected: 390 },
  { name: 'compact phone', width: 390, expected: 390 },
  { name: 'phone', width: 430, expected: 620 },
  { name: 'tablet portrait', width: 768, expected: 1180 },
  { name: 'tablet landscape', width: 1024, expected: 1180 },
  { name: 'desktop baseline', width: 1366, expected: 1500 },
  { name: 'narrow Windows window', width: 1180, expected: 1180 },
  { name: '16:10 laptop', width: 1440, expected: 1500 },
  { name: 'desktop', width: 1920, expected: null },
  { name: 'portrait 2K', width: 2560, expected: null },
  { name: 'ultrawide', width: 3440, expected: null },
  { name: '4K', width: 3840, expected: null },
  { name: '5K ultrawide', width: 5120, expected: null }
]) {
  if (viewport.expected !== null) {
    assert(
      css.includes(`@media (max-width: ${viewport.expected}px)`),
      `${viewport.name} (${viewport.width}px) has no matching dashboard breakpoint`
    );
  }
}

/* Production geometry recorded before the fix. The stale `left-bottom` area
   split each left column into two implicit tracks. The corrected CSS contract
   makes the user card and lower sections span the complete left track while
   keeping the right column top-aligned. */
for (const geometry of [
  { viewport: '1366x768', main: 924, measuredLeft: 601, collapsedUser: 105 },
  { viewport: '1920x1080', main: 1474, measuredLeft: 967, collapsedUser: 289 },
  { viewport: '2560x1440', main: 2118, measuredLeft: 1396, collapsedUser: 504 },
  { viewport: '3440x1440', main: 2998, measuredLeft: 1983, collapsedUser: 797 },
  { viewport: '3840x2160', main: 3398, measuredLeft: 2249, collapsedUser: 931 }
]) {
  const available = geometry.main - 24;
  const right = Math.max(300, available / 3);
  const correctedLeft = available - right;
  assert(geometry.collapsedUser < correctedLeft, `${geometry.viewport} fixture must reproduce the former collapse`);
  assert(
    Math.abs(correctedLeft - geometry.measuredLeft) <= 1,
    `${geometry.viewport} corrected user card must span the complete 2fr left track`
  );
  assert(right >= 299, `${geometry.viewport} right column must retain a readable width`);
  assert(correctedLeft >= right * 1.9, `${geometry.viewport} desktop columns must preserve the intended 2:1 hierarchy`);
}

assert(patcher.includes('public static function patchNodeFlags'), 'node flag asset patch is missing');
assert(patcher.includes('class: "luck-node-flag"'), 'node flag patch does not emit the visible flag glyph');
assert(patcher.includes('luck-flags.svg?v=1#${flagAssetCode}'), 'node flags must use the packaged SVG sprite');
assert(patcher.includes('const mobileFlagCode ='), 'mobile node cards need an independent portable flag code');
assert(patcher.includes('luck-flags.svg?v=1#${mobileFlagAssetCode}'), 'mobile node cards must use the packaged SVG sprite');
assert(patcher.includes('toDisplayString(mobileDisplayName)'), 'mobile node names must remove duplicate flag emoji');
assert(patcher.includes('public static function patchNodeScrollbar'), 'node scrollbar prop patch is missing');
assert(patcher.includes('"scrollbar-props": { trigger: "none" }'), 'node table must render its horizontal rail without hover');
assert(!patcher.includes('String.fromCodePoint(...flagCode'), 'node flags must not depend on Windows emoji rendering');
assert(patcher.includes('displayName'), 'duplicate flag emoji must be removed from the node name');
assert(routes.includes('LuckThemeAssetPatcher::patchNodeFlags($fixedContents)'), 'node flag patch is not applied while publishing Luck assets');
assert(routes.includes('LuckThemeAssetPatcher::patchNodeScrollbar($fixedContents)'), 'node scrollbar patch is not applied while publishing Luck assets');

console.log('Verified responsive dashboard, readable labels, node scrolling and local node flags.');
