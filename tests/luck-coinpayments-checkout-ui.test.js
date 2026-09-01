const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const patcher = fs.readFileSync('app/Services/LuckThemeAssetPatcher.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');
const ciSmoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

const bridgeMatch = patcher.match(/\$coinPaymentsBridge = <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert(bridgeMatch, 'The generated CoinPayments browser bridge could not be extracted.');
assert.doesNotThrow(
  () => new vm.Script(bridgeMatch[1], { filename: 'luck-coinpayments-checkout-bridge.js' }),
  'The generated CoinPayments browser bridge must remain valid JavaScript.',
);

const copyMatch = bridgeMatch[1].match(/const copy = (\{[\s\S]*?\});\r?\n\s*const normalizedLanguage/);
assert(copyMatch, 'The embedded CoinPayments locale catalogue could not be extracted.');
const checkoutCopy = vm.runInNewContext(`(${copyMatch[1]})`);
const requiredLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];
const requiredCopyKeys = ['title', 'subtitle', 'order', 'secure', 'loading', 'waiting', 'checking', 'paid', 'cancelled', 'error', 'invalid', 'frameHelp', 'open', 'back', 'close', 'check', 'remaining', 'expired', 'frameTitle'];
assert.deepStrictEqual(Object.keys(checkoutCopy).sort(), requiredLocales.slice().sort(), 'The checkout locale set changed unexpectedly.');
for (const locale of requiredLocales) {
  assert.deepStrictEqual(Object.keys(checkoutCopy[locale]).sort(), requiredCopyKeys.slice().sort(), `${locale} checkout copy is incomplete.`);
  for (const key of requiredCopyKeys) {
    assert.strictEqual(typeof checkoutCopy[locale][key], 'string', `${locale}.${key} must be a string.`);
    assert(checkoutCopy[locale][key].trim(), `${locale}.${key} must not be empty.`);
  }
}

const allowedHost = /^(?:[a-c]-)?checkout\.coinpayments\.net$/i;
for (const host of ['checkout.coinpayments.net', 'a-checkout.coinpayments.net', 'b-checkout.coinpayments.net', 'c-checkout.coinpayments.net']) {
  assert(allowedHost.test(host), `Official CoinPayments checkout host was rejected: ${host}`);
}
for (const host of ['coinpayments.net', 'api.coinpayments.net', 'd-checkout.coinpayments.net', 'checkout.coinpayments.net.example.com', 'evilcheckout.coinpayments.net']) {
  assert(!allowedHost.test(host), `Untrusted checkout host was accepted: ${host}`);
}

for (const marker of [
  'window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__',
  '/^(?:[a-c]-)?checkout\\.coinpayments\\.net$/i',
  'candidate.protocol === "https:"',
  'candidate.port === "" || candidate.port === "443"',
  '!candidate.username && !candidate.password',
  'frame.allow = "clipboard-read; clipboard-write; payment"',
  'frame.referrerPolicy = "strict-origin-when-cross-origin"',
  'external.target = "_blank"',
  'external.rel = "noopener noreferrer"',
  'external.hidden = true',
  'data-luck-coinpayments-checkout',
  'role", "dialog"',
  'aria-modal", "true"',
  'state.setAttribute("role", "status")',
  'state.setAttribute("aria-live", "polite")',
  'overlay.dir = selectedLocale === "fa-IR" ? "rtl" : "ltr"',
  '@media(max-width:700px)',
  '@media(max-height:520px) and (orientation:landscape)',
  'height:100vh;height:100dvh',
  'height:100dvh',
  '.luck-cp-frame-wrap{min-height:0}',
  'safe-area-inset-bottom',
  'const statusUrl = "/payment/status/"',
  'credentials: "same-origin"',
  'cache: "no-store"',
  'orderStatus === 1 || orderStatus === 3',
  'window.setInterval(poll, 5000)',
  'window.location.assign(returnUrl)',
  'window.clearInterval(pollTimer)',
  'window.clearInterval(clockTimer)',
  'window.clearInterval(routeTimer)',
  'document.removeEventListener("keydown", onKeydown)',
  'window.addEventListener("popstate", onRouteChange)',
  'window.addEventListener("hashchange", onRouteChange)',
  'window.removeEventListener("popstate", onRouteChange)',
  'window.removeEventListener("hashchange", onRouteChange)',
  'window.addEventListener("pagehide", cleanup)',
  'window.removeEventListener("pagehide", cleanup)',
  'window.location.pathname + window.location.search + window.location.hash !== initialRoute',
  'if (!isCurrent()) return;',
  'if (event.key !== "Tab" || !isCurrent()) return;',
  'if (!overlay.contains(document.activeElement))',
  'overlay.remove()',
  'copy[selectedLocale]',
  'const exactLocale = Object.keys(copy).find',
  'primaryLanguage === "zh"',
  '"vi-VN"',
  '"en-US"',
  '"zh-CN"',
  '"zh-TW"',
  '"ja-JP"',
  '"ko-KR"',
  '"fa-IR"',
  '"ru-RU"',
]) {
  assert(patcher.includes(marker), `CoinPayments checkout UI is missing: ${marker}`);
}

assert(
  patcher.includes('String(method && method.payment || "").toLowerCase() === "coinpayments"'),
  'Only CoinPayments should use the embedded checkout; other external gateways must keep their normal redirect.',
);
assert(
  patcher.includes('safeCheckoutUrl ? labels.frameHelp : labels.invalid'),
  'Invalid provider URLs must render a safe local error instead of an external fallback.',
);
assert(
  !patcher.includes('postMessage') && !patcher.includes('message.origin'),
  'Provider iframe messages must never be treated as authoritative payment confirmation.',
);
assert(
  patcher.indexOf('if (!isCurrent()) return;', patcher.indexOf('const response = await fetch(statusUrl'))
    < patcher.indexOf('const payload = await response.json();'),
  'A disposed or superseded checkout must stop before parsing a late status response.',
);
assert(
  patcher.indexOf('if (!isCurrent()) return;', patcher.indexOf('const payload = await response.json();'))
    < patcher.indexOf('if (orderStatus === 1 || orderStatus === 3)'),
  'A disposed or superseded checkout must stop before scheduling a paid redirect.',
);

for (const [name, source] of [
  ['routes', routes],
  ['published-image smoke', ciSmoke],
  ['production deploy gate', deploy],
]) {
  assert(source.includes('payment-v6'), `${name} must select the cache-busted CoinPayments-capable dialog chunk`);
}
for (const [name, source] of [['published-image smoke', ciSmoke], ['production deploy gate', deploy]]) {
  assert(source.includes('window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__'), `${name} must verify the embedded checkout in the published asset`);
  assert(source.includes('clipboard-read; clipboard-write; payment'), `${name} must verify the iframe permissions in the published asset`);
  assert(source.includes('const statusUrl = "/payment/status/"'), `${name} must verify backend-authoritative status polling in the published asset`);
}
assert(routes.includes("'C0KnXkt1', '-payment-v4'"), 'The order checkout chunk must be cache-busted to payment-v4.');
assert(routes.includes("'-payment-v4.js', $target"), 'The physical order checkout chunk must use payment-v4.');
assert(routes.includes("'Cache-Control' => 'no-store, private, max-age=0'"), 'Payment polling responses must never be cached.');

console.log('Verified responsive same-site CoinPayments checkout, strict provider URL policy, polling, cleanup and cache gates.');
