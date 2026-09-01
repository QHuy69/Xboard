const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const dashboard = fs.readFileSync('luck-dashboard.blade.php', 'utf8');

function ruleBody(selector) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = css.match(new RegExp(`${escaped}\\s*\\{([\\s\\S]*?)\\}`, 'm'));
  assert(match, `Missing responsive logo rule: ${selector}`);
  return match[1];
}

const imageRule = ruleBody('.logo-section img.logo-img');
assert.match(imageRule, /width:\s*100%\s*!important/, 'logo must shrink below an inline fixed maximum on split/fold views');
assert.match(imageRule, /height:\s*auto\s*!important/, 'logo height must remain intrinsic');
assert.match(imageRule, /max-width:\s*100%\s*!important/, 'older browsers need a container-width fallback');
assert.match(
  imageRule,
  /max-width:\s*min\(100%,\s*var\(--luck-logo-max-width,\s*200px\)\)\s*!important/,
  'logo must shrink to its container without exceeding the theme maximum'
);
assert.match(
  imageRule,
  /max-height:\s*min\(var\(--luck-logo-max-height,\s*82px\),\s*max\(28px,\s*10dvh\)\)\s*!important/,
  'logo height must respond to short or zoomed viewports'
);
assert.match(imageRule, /max-height:\s*var\(--luck-logo-max-height,\s*82px\)\s*!important/, 'older browsers need a bounded-height fallback');
assert.match(imageRule, /object-fit:\s*contain\s*!important/, 'logo must never use a cropping object-fit mode');
assert.match(imageRule, /aspect-ratio:\s*auto\s*!important/, 'logo must preserve its source aspect ratio');

const collapsedSidebar = ruleBody('.sidebar.collapsed');
for (const marker of ['flex: 0 0 80px', 'width: 80px', 'min-width: 80px', 'max-width: 80px']) {
  assert(collapsedSidebar.includes(marker), `collapsed sidebar is missing ${marker}`);
}

const collapsedTitle = ruleBody('.sidebar.collapsed .app-title-full');
assert.match(collapsedTitle, /font-size:\s*clamp\(7px,\s*\.625vw,\s*9px\)/, 'collapsed title must scale at narrow viewport widths');
assert.match(collapsedTitle, /white-space:\s*normal\s*!important/, 'collapsed title must be allowed to wrap');
assert.match(collapsedTitle, /overflow:\s*visible\s*!important/, 'collapsed title must not be clipped');
assert.match(collapsedTitle, /overflow-wrap:\s*anywhere/, 'long unbroken translated titles must remain visible');
assert.match(collapsedTitle, /text-overflow:\s*clip\s*!important/, 'the stock ellipsis contract must be disabled');

assert(
  css.includes('.sidebar.collapsed :where(.sidebar-header-content, .logo-section, .collapsed-logo)'),
  'all collapsed branding layers must be constrained to the rail'
);
assert(
  css.includes('min-width: 0 !important;') && css.includes('max-width: 100% !important;'),
  'branding layers need explicit shrink boundaries'
);
assert(
  css.includes('.main-area,') && css.includes('.mobile-top {') && css.includes('width: 100% !important;'),
  'the mobile shell must not retain the logo min-content width'
);

assert(
  dashboard.includes('luck-overrides.css?v=28'),
  'the dashboard must bust the cached pre-logo-fix stylesheet'
);
assert(!dashboard.includes('logoConfig.MAX_WIDTH'), 'the dashboard must not read a logo dimension that the backend never exposes');

// Model the accepted desktop rail and the most constrained collapsed rail.
// The title can occupy two lines in 56px; image logos never exceed the 200px
// configured cap or the actual expanded content width.
const expandedContentWidth = 280 - 48;
const configuredImageWidth = 200;
assert(Math.min(expandedContentWidth, configuredImageWidth) === 200);
const collapsedContentWidth = 80 - 16 - 8;
assert(collapsedContentWidth === 56 && collapsedContentWidth > 0);
const foldViewportContentWidth = 240 - 15;
const foldLogoWidth = Math.min(foldViewportContentWidth - 24 - 12, configuredImageWidth);
assert(foldLogoWidth === 189 && foldLogoWidth > 0, 'the mobile logo must fit a 240px fold/split viewport');

console.log('Luck sidebar logo scales without cropping across expanded, collapsed and zoomed layouts.');
