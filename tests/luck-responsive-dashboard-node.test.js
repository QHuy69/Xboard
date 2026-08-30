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
  /container:\s*luck-user-card\s*\/\s*inline-size/,
  'dashboard card must respond to its real available width, not only the viewport'
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
  /\.user-subscription-item \.subscription-label\[data-v-3709f5eb\],[\s\S]*?overflow-wrap:\s*normal[\s\S]*?word-break:\s*keep-all/,
  'subscription labels must wrap by words instead of one character per line'
);
expect(
  /@media \(max-width:\s*390px\)[\s\S]*?\.user-subscription-item\[data-v-3709f5eb\][\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/,
  '320-390px phones and narrow foldables must stack label/value rows'
);
expect(
  /\.servers-table-container\[data-v-85145c70\][\s\S]*?overflow-x:\s*hidden\s*!important/,
  'the node card must delegate horizontal scrolling to one inner owner'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-data-table-wrapper[\s\S]*?overflow-x:\s*hidden\s*!important/,
  'the Naive wrapper must not create a second horizontal scrollbar'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-data-table-base-table-body[\s\S]*?overflow-x:\s*auto\s*!important/,
  'the Naive table body must be the sole horizontal scroll owner'
);
expect(
  /\.compact-table\[data-v-85145c70\] \.n-data-table-table[\s\S]*?min-width:\s*960px/,
  'node columns must preserve readable widths and scroll as one table'
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

assert(patcher.includes('public static function patchNodeFlags'), 'node flag asset patch is missing');
assert(patcher.includes('class: "luck-node-flag"'), 'node flag patch does not emit the visible flag glyph');
assert(patcher.includes('luck-flags.svg?v=1#${flagAssetCode}'), 'node flags must use the packaged SVG sprite');
assert(!patcher.includes('String.fromCodePoint(...flagCode'), 'node flags must not depend on Windows emoji rendering');
assert(patcher.includes('displayName'), 'duplicate flag emoji must be removed from the node name');
assert(routes.includes('LuckThemeAssetPatcher::patchNodeFlags($fixedContents)'), 'node flag patch is not applied while publishing Luck assets');

console.log('Verified responsive dashboard, readable labels, node scrolling and local node flags.');
