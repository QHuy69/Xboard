const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');

assert.match(
  css,
  /\.subscription-dialog-overlay\s*\{[\s\S]*?position:\s*fixed\s*!important;[\s\S]*?inset:\s*0\s*!important;/,
  'the portalled subscription QR overlay must be pinned to the visual viewport'
);
assert(template.includes('var syncSubscriptionDialogPortal = function ()'), 'subscription dialog portal runtime is missing');
assert(template.includes("document.querySelectorAll('.subscription-dialog-overlay')"), 'portal runtime must find every generated subscription overlay');
assert(template.includes('document.body.appendChild(overlay)'), 'subscription overlays must leave transformed route scrollers');
assert.match(
  template,
  /new MutationObserver\(function \(\) \{[\s\S]*?syncSubscriptionDialogPortal\(\);[\s\S]*?scheduleRefresh\(\);/,
  'the portal must run in the app mutation microtask before the delayed refresh'
);
assert(template.includes('luck-overrides.css?v=24'), 'the QR positioning fix needs a fresh CSS cache key');

assert(!/\.content-wrapper\s*\{[\s\S]*?transform:\s*none\s*!important/.test(css), 'the fix must not flatten the desktop dashboard transform');
assert(!/\.mobile-dashboard \.mobile-content\s*\{[\s\S]*?backdrop-filter:\s*none\s*!important/.test(css), 'the fix must not remove the mobile dashboard blur');

console.log('Verified subscription QR portal and viewport anchoring across both dashboard shells.');
