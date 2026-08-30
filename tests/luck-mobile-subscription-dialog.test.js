const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');

assert.match(
  css,
  /\.mobile-dashboard \.mobile-content\s*\{[\s\S]*?-webkit-backdrop-filter:\s*none\s*!important;[\s\S]*?backdrop-filter:\s*none\s*!important;/,
  'the mobile scroll container must not establish a fixed-position containing block'
);
assert.match(
  css,
  /@supports selector\(\.mobile-content:has\(\*\)\)[\s\S]*?\.mobile-dashboard \.mobile-content:not\(:has\(\.subscription-dialog-overlay\)\)[\s\S]*?backdrop-filter:\s*blur\(20px\)\s*!important;/,
  'modern browsers should retain the normal mobile blur while the QR dialog is closed'
);
assert.match(
  css,
  /\.mobile-dashboard \.subscription-dialog-overlay\s*\{[\s\S]*?position:\s*fixed\s*!important;[\s\S]*?inset:\s*0\s*!important;/,
  'the subscription QR overlay must be pinned to the visual viewport'
);
assert(template.includes('luck-overrides.css?v=23'), 'the QR positioning fix needs a fresh CSS cache key');

const functionalRule = css.indexOf('.mobile-dashboard .mobile-content {');
const portraitMedia = css.indexOf('@media (max-width: 768px)', functionalRule);
assert(functionalRule >= 0 && portraitMedia > functionalRule, 'QR anchoring must also apply to wide landscape phones');

console.log('Verified mobile subscription QR overlay anchoring across phone and landscape viewports.');
