const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('luck-overrides.css', 'utf8');
const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');
const ciSmoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

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
assert.match(overlayRule, /position:\s*fixed\s*!important;/, 'the teleported overlay must use fixed positioning');
assert.match(overlayRule, /inset:\s*0\s*!important;/, 'the teleported overlay must cover the visual viewport');

for (const marker of [
  'public static function patchSubscriptionDialogTeleport',
  'name: "PortalledSubscriptionDialog"',
  'inheritAttrs: false',
  'createVNode(Teleport, { to: "body" }',
  'createVNode(StockSubscriptionDialog, attrs)',
  'T as Teleport',
  'preg_match_all($importPattern, $contents, $importMatches) !== 1',
  'preg_match_all($componentPattern, $contents, $componentMatches) !== 1',
  'public static function rewriteSubscriptionDialogAssetImport',
  "'-payment-v4.js'",
]) {
  assert(patcher.includes(marker), `subscription Teleport patch is missing: ${marker}`);
}

assert(routes.includes('LuckThemeAssetPatcher::patchSubscriptionDialogTeleport($fixedContents)'), 'the C6 dialog chunk must receive the Teleport patch');
assert(routes.includes('LuckThemeAssetPatcher::rewriteSubscriptionDialogAssetImport($fixedContents)'), 'the main entry must select the normalized dialog chunk');
assert(routes.includes('LuckThemeAssetPatcher::subscriptionDialogAssetName($runtimeFile)'), 'the physical dialog chunk must use the normalized v4 name');

assert(template.includes('BBbuoBq5-fresh.js?v=63'), 'the Teleport entry graph needs a fresh browser/CDN URL');
assert(!template.includes('syncSubscriptionDialogPortal'), 'the dashboard must not manually move Vue-owned overlays');
assert(!template.includes('document.body.appendChild(overlay)'), 'raw DOM portalling can orphan the dialog on responsive remount');
assert(template.includes('new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true })'), 'teleported dialogs must still receive icon and fallback enhancements');

for (const [name, source] of [['published-image smoke', ciSmoke], ['production deploy gate', deploy]]) {
  assert(source.includes('subscription_dialog_asset'), `${name} must resolve the lazy subscription-dialog chunk`);
  assert(source.includes('shared_runtime_asset'), `${name} must reject a cached nested shared runtime`);
  assert(source.includes('./BBbuoBq5*-runtime-v3.js'), `${name} must require shared runtime v3`);
  assert(source.includes('./C6e3mGRa*-payment-v4.js'), `${name} must require the normalized v4 dialog chunk`);
  assert(source.includes('PortalledSubscriptionDialog'), `${name} must require the Vue-owned Teleport wrapper`);
  assert(source.includes('T as Teleport'), `${name} must require the Vue Teleport runtime import`);
}

const scriptAnchor = template.indexOf("var banner = document.getElementById('luck-donate-banner');");
const scriptStart = template.lastIndexOf('<script>', scriptAnchor) + '<script>'.length;
const scriptEnd = template.indexOf('</script>', scriptAnchor);
assert(scriptAnchor >= 0 && scriptStart >= '<script>'.length && scriptEnd > scriptStart, 'dashboard enhancement script is missing');
const renderedScript = template.slice(scriptStart, scriptEnd).replace('@json($luckDonatePlanIds)', '[1]');
assert.doesNotThrow(() => new vm.Script(renderedScript), 'dashboard enhancement script must remain valid after Blade rendering');

assert(!/\.content-wrapper(?:\[[^\]]+\])?[^,{]*\{[^{}]*transform:\s*none\s*!important;/.test(css), 'the fix must not flatten the desktop dashboard transform');
assert(!/\.mobile-content(?:\[[^\]]+\])?[^,{]*\{[^{}]*backdrop-filter:\s*none\s*!important;/.test(css), 'the fix must not remove the mobile dashboard blur');

console.log('Verified Vue-owned subscription QR Teleport and release gates across both dashboard shells.');
