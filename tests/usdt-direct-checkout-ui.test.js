const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const route = fs.readFileSync('routes/web.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/UsdtDirectCheckoutController.php', 'utf8');
const orderController = fs.readFileSync('app/Http/Controllers/V1/User/OrderController.php', 'utf8');
const adminController = fs.readFileSync('app/Http/Controllers/V2/Admin/PaymentController.php', 'utf8');
const view = fs.readFileSync('resources/views/payment/usdt-direct.blade.php', 'utf8');
const script = fs.readFileSync('public/payment/usdt-direct.js', 'utf8');
const style = fs.readFileSync('public/payment/usdt-direct.css', 'utf8');
const supportedLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

assert.doesNotThrow(() => new vm.Script(script, { filename: 'usdt-direct.js' }), 'USDT checkout JavaScript must parse.');

for (const marker of [
  "Route::get('/pay/usdt/{opaqueToken}'",
  "Route::get('/pay/usdt/{opaqueToken}/status'",
  "Route::get('/pay/usdt/{opaqueToken}/qr.svg'",
  "->where('opaqueToken', '[A-Za-z0-9_-]{32,128}')"
]) {
  assert(route.includes(marker), `USDT checkout route is missing: ${marker}`);
}
assert(route.indexOf("Route::get('/pay/usdt/{opaqueToken}'") < route.indexOf("Route::get('/{path}'"),
  'USDT checkout routes must be registered before the Luck SPA catch-all.');

for (const marker of [
  "private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/D'",
  "hash('sha256', $opaqueToken)",
  "hash_equals((string) $invoice->public_token_hash, $tokenHash)",
  "where('public_token_hash', $tokenHash)",
  "(string) $order->payment->payment !== 'UsdtDirect'",
  "'Cache-Control' => 'no-store, private, max-age=0'",
  "'Referrer-Policy' => 'no-referrer'",
  "'X-Frame-Options' => 'DENY'",
  "'X-Robots-Tag' => 'noindex, nofollow, noarchive'",
  "'Cross-Origin-Resource-Policy' => 'same-origin'",
  "frame-ancestors 'none'",
  "'status' => $this->checkoutStatus($invoice, $order)",
  "Order::STATUS_PROCESSING, Order::STATUS_COMPLETED",
  "strtolower((string) $invoice->network) !== 'tron'",
  "hash_equals(self::USDT_TRC20_CONTRACT, (string) $invoice->token_contract)",
  "[1-9][0-9]{0,39}",
  "new SvgImageBackEnd()",
  "writeString($address)"
]) {
  assert(controller.includes(marker), `USDT checkout controller is missing safety behavior: ${marker}`);
}
assert(!controller.includes("where('trade_no', $opaqueToken)"), 'Opaque checkout tokens must never be treated as trade numbers.');
assert(!route.includes('/pay/usdt/{tradeNo}'), 'USDT checkout must not expose enumerable trade numbers.');

assert(orderController.match(/in_array\(\$payment->payment, \['CoinPayments', 'UsdtDirect'\], true\)/g)?.length >= 2,
  'Checkout and customer payment-method listing must reject invalid USDT configuration.');
assert(orderController.includes("if ($payment->payment === 'UsdtDirect')")
  && orderController.includes('OrderService::beginUsdtDirectCheckout('),
  'USDT Direct must use its dedicated atomic checkout allocation instead of the generic provider flow.');
assert(orderController.includes("preg_match('#^/pay/usdt/[A-Za-z0-9_-]{32,128}$#D', $checkoutPath)")
  && orderController.includes("'data' => $checkoutPath"),
  'USDT Direct must return a same-origin relative checkout path, never a Referer-derived host.');
assert(adminController.includes('->validateConfiguration();'), 'Admin enable toggle must validate payment configuration first.');
assert(adminController.includes("validateConfigurationShape($submittedConfig)"), 'Admin must permit a valid disabled draft without treating it as payable.');

for (const locale of supportedLocales.filter((locale) => locale !== 'en-US')) {
  assert(view.includes(`$locale === '${locale}'`), `${locale} is missing from the USDT checkout locale selector.`);
}
for (const marker of [
  'data-usdt-checkout',
  'data-status-url="{{ $statusUrl }}"',
  'data-initial-status="{{ $initialStatus }}"',
  'data-copy-value="{{ $amountUsdt }}"',
  'data-copy-value="{{ $receivingAddress }}"',
  'data-refresh-status',
  'data-payment-status',
  'aria-live="polite"',
  'dir="{{ $locale === \'fa-IR\' ? \'rtl\' : \'ltr\' }}"',
  'Chỉ gửi USDT TRC20',
  '仅发送 USDT TRC20',
  'USDT TRC20のみ送金',
  'فقط USDT TRC20 ارسال کنید',
  'Отправляйте только USDT TRC20'
]) {
  assert(view.includes(marker), `USDT checkout view is missing UI/i18n behavior: ${marker}`);
}

function topLevelArguments(source, openIndex) {
  let depth = 1;
  let argumentStart = openIndex + 1;
  const argumentsList = [];
  let quote = null;
  let escaped = false;
  for (let index = openIndex + 1; index < source.length; index += 1) {
    const character = source[index];
    if (quote !== null) {
      if (escaped) escaped = false;
      else if (character === '\\') escaped = true;
      else if (character === quote) quote = null;
      continue;
    }
    if (character === "'" || character === '"') quote = character;
    else if (character === '(') depth += 1;
    else if (character === ')') {
      depth -= 1;
      if (depth === 0) {
        argumentsList.push(source.slice(argumentStart, index).trim());
        return argumentsList;
      }
    } else if (character === ',' && depth === 1) {
      argumentsList.push(source.slice(argumentStart, index).trim());
      argumentStart = index + 1;
    }
  }
  throw new Error('Unterminated $text() call in USDT checkout view');
}

let copyCallCount = 0;
for (let index = view.indexOf('$text('); index !== -1; index = view.indexOf('$text(', index + 6)) {
  const argumentsList = topLevelArguments(view, index + '$text'.length);
  assert.strictEqual(argumentsList.length, supportedLocales.length,
    `USDT checkout $text() at offset ${index} must contain all ${supportedLocales.length} locales.`);
  for (const localeIndex of [2, 3, 4, 5, 6, 7]) {
    assert.notStrictEqual(argumentsList[localeIndex], argumentsList[1],
      `USDT checkout locale ${supportedLocales[localeIndex]} falls back to English at offset ${index}.`);
  }
  copyCallCount += 1;
}
assert(copyCallCount >= 25, `Expected a fully localized USDT checkout, found ${copyCallCount} copy calls.`);

for (const marker of [
  'candidate.origin !== window.location.origin',
  'credentials: "same-origin"',
  'cache: "no-store"',
  'new AbortController()',
  'paymentStatus === "paid"',
  'paymentStatus === "confirming"',
  'paymentStatus === "manual_review"',
  'applyPaymentStatus(initialStatus, defaultReturnUrl)',
  'window.location.assign(target.href)',
  'window.addEventListener("pagehide", dispose)',
  'document.visibilityState === "hidden"',
  'navigator.clipboard.writeText(value)',
  'document.execCommand("copy")'
]) {
  assert(script.includes(marker), `USDT checkout script is missing safety/state behavior: ${marker}`);
}
assert(!script.includes('tronscan.org') && !script.includes('trongrid.io'), 'The browser must never call blockchain providers directly.');

for (const marker of [
  'grid-template-columns: minmax(310px, .92fr) minmax(430px, 1.08fr)',
  '@media (max-width: 840px)',
  '@media (max-width: 520px)',
  '@media (max-width: 340px)',
  '@media (max-height: 640px) and (orientation: landscape)',
  'min-height: 100dvh',
  'env(safe-area-inset-bottom)',
  'overflow-wrap: anywhere',
  '.usdt-payment {\n    order: 1;',
  'prefers-reduced-motion: reduce'
]) {
  assert(style.includes(marker), `USDT checkout CSS is missing responsive behavior: ${marker}`);
}
assert(!/(?:url\(|@import)[^;]*https?:\/\//i.test(style), 'USDT checkout CSS must not load remote assets.');

console.log(`USDT Direct same-site checkout is opaque-token protected, responsive and localized across ${supportedLocales.length} locales (${copyCallCount} copy calls).`);
