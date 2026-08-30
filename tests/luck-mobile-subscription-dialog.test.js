const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');

function cssRuleBody(selector) {
  const marker = `${selector} {`;
  const start = css.indexOf(marker);
  assert(start >= 0, `missing CSS rule: ${selector}`);
  const bodyStart = start + marker.length;
  const bodyEnd = css.indexOf('}', bodyStart);
  assert(bodyEnd > bodyStart, `unterminated CSS rule: ${selector}`);
  return css.slice(bodyStart, bodyEnd);
}

const overlayRule = cssRuleBody('.subscription-dialog-overlay');
assert.match(overlayRule, /position:\s*fixed\s*!important;/, 'the portalled overlay must use fixed positioning');
assert.match(overlayRule, /inset:\s*0\s*!important;/, 'the portalled overlay must cover the visual viewport');

const functionMarker = 'var syncSubscriptionDialogPortal = function () {';
const functionStart = template.indexOf(functionMarker);
const functionEnd = template.indexOf('\n      };', functionStart);
assert(functionStart >= 0 && functionEnd > functionStart, 'subscription dialog portal runtime is missing');
const portalFunction = template.slice(functionStart, functionEnd);
assert(portalFunction.includes("document.querySelectorAll('.subscription-dialog-overlay')"), 'portal runtime must find every generated subscription overlay');
assert(portalFunction.includes('overlay.parentElement !== document.body'), 'portal runtime must be idempotent');
assert(portalFunction.includes('document.body.appendChild(overlay)'), 'subscription overlays must leave transformed route scrollers');

const appMarker = "var app = document.getElementById('app');";
const appStart = template.indexOf(appMarker, functionEnd);
const observerStart = template.indexOf('new MutationObserver(function () {', appStart);
const observeCall = '}).observe(app, { childList: true, subtree: true });';
const observerEnd = template.indexOf(observeCall, observerStart);
assert(appStart >= 0 && observerStart > appStart && observerEnd > observerStart, 'the app subtree observer is missing');
const observerBody = template.slice(observerStart, observerEnd + observeCall.length);
const portalCall = observerBody.indexOf('syncSubscriptionDialogPortal();');
const refreshCall = observerBody.indexOf('scheduleRefresh();');
assert(portalCall >= 0 && refreshCall > portalCall, 'the portal must run before the delayed refresh');

const scriptAnchor = template.indexOf("var banner = document.getElementById('luck-donate-banner');");
const scriptStart = template.lastIndexOf('<script>', scriptAnchor) + '<script>'.length;
const scriptEnd = template.indexOf('</script>', scriptAnchor);
assert(scriptAnchor >= 0 && scriptStart >= '<script>'.length && scriptEnd > scriptStart, 'dashboard enhancement script is missing');
const renderedScript = template.slice(scriptStart, scriptEnd).replace('@json($luckDonatePlanIds)', '[1]');
assert.doesNotThrow(() => new vm.Script(renderedScript), 'dashboard enhancement script must remain valid after Blade rendering');

assert(template.includes('luck-overrides.css?v=24'), 'the QR positioning fix needs a fresh CSS cache key');

assert(!/\.content-wrapper(?:\[[^\]]+\])?[^,{]*\{[^{}]*transform:\s*none\s*!important;/.test(css), 'the fix must not flatten the desktop dashboard transform');
assert(!/\.mobile-content(?:\[[^\]]+\])?[^,{]*\{[^{}]*backdrop-filter:\s*none\s*!important;/.test(css), 'the fix must not remove the mobile dashboard blur');

console.log('Verified subscription QR portal and viewport anchoring across both dashboard shells.');
