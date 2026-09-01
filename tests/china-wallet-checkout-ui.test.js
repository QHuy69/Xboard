const assert = require('assert');
const fs = require('fs');

const route = fs.readFileSync('routes/web.php', 'utf8');
const view = fs.readFileSync('resources/views/payment/china-wallet-checkout.blade.php', 'utf8');
const script = fs.readFileSync('public/payment/china-wallet-checkout.js', 'utf8');
const style = fs.readFileSync('public/payment/china-wallet-checkout.css', 'utf8');
const supportedLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

assert(route.includes("app()->environment(['local', 'testing'])"), 'The frontend-only preview must not be registered in production.');
assert(route.includes("/_preview/payment/china-wallets"), 'The local China-wallet preview route is missing.');
assert(route.includes("['wechatpay', 'alipay']"), 'The preview route must accept only WeChat Pay and Alipay.');
assert(route.includes("FILTER_VALIDATE_FLOAT") && route.includes('$amount > 999999'), 'The preview amount is not bounded and validated.');
assert(route.includes("'Cache-Control', 'no-store, private, max-age=0'"), 'The payment preview must not be cached.');

for (const locale of supportedLocales) {
  assert(route.includes(`'${locale}'`), `${locale} is missing from the China-wallet preview route.`);
}
for (const locale of supportedLocales.filter((locale) => locale !== 'en-US')) {
  assert(view.includes(`$locale === '${locale}'`), `${locale} is missing from the China-wallet checkout locale selector.`);
}

for (const marker of [
  'data-china-wallet-checkout',
  'data-preview="{{ $previewMode ? \'1\' : \'0\' }}"',
  'data-wallet-option="wechatpay"',
  'data-wallet-option="alipay"',
  'data-payment-qr-image',
  'data-payment-qr-demo',
  'data-create-payment',
  'aria-live="polite"',
  'dir="{{ $locale === \'fa-IR\' ? \'rtl\' : \'ltr\' }}"',
  'QR DEMO · KHÔNG THANH TOÁN',
  '演示二维码 · 无法付款',
  'UI 미리보기',
  'این فقط پیش‌نمایش رابط است',
  'Это предварительный просмотр интерфейса'
]) {
  assert(view.includes(marker), `The checkout view is missing required UI/i18n behavior: ${marker}`);
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
  throw new Error('Unterminated $text() call in China-wallet checkout view');
}

let copyCallCount = 0;
for (let index = view.indexOf('$text('); index !== -1; index = view.indexOf('$text(', index + 6)) {
  const argumentsList = topLevelArguments(view, index + '$text'.length);
  assert.strictEqual(argumentsList.length, supportedLocales.length,
    `China-wallet $text() call at offset ${index} has ${argumentsList.length} locale values instead of ${supportedLocales.length}`);
  for (const localeIndex of [2, 3, 4, 5, 6, 7]) {
    assert.notStrictEqual(argumentsList[localeIndex], argumentsList[1],
      `China-wallet locale ${supportedLocales[localeIndex]} falls back to English at offset ${index}`);
  }
  copyCallCount += 1;
}
assert(copyCallCount >= 24, `Expected a fully localized checkout surface, found ${copyCallCount} copy calls.`);

for (const marker of [
  'const allowedWallets = new Set(["wechatpay", "alipay"])',
  'if (previewMode || !createEndpoint)',
  'candidate.origin !== window.location.origin',
  'if (!/^https?:$/.test(candidate.protocol))',
  'credentials: "same-origin"',
  'cache: "no-store"',
  'qr_image_url',
  'status_url',
  'paymentStatus === "paid"',
  'redirectAfterPaid(payload.return_url)',
  'window.addEventListener("pagehide", stopTimers)'
]) {
  assert(script.includes(marker), `The checkout controller is missing a safety/state marker: ${marker}`);
}
assert(!script.includes('wechatpay.cn') && !script.includes('alipay.com'), 'Frontend checkout must not call provider APIs directly.');
assert(script.indexOf('if (previewMode || !createEndpoint)') < script.indexOf('fetch(endpoint.href,', script.indexOf('const createPayment')),
  'Preview mode can reach the create-payment network request.');

for (const marker of [
  'grid-template-columns: minmax(300px, .88fr) minmax(410px, 1.12fr)',
  '@media (max-width: 820px)',
  '@media (max-width: 480px)',
  '@media (max-width: 360px)',
  '@media (max-height: 620px) and (orientation: landscape)',
  'min-height: 100dvh',
  'env(safe-area-inset-bottom)',
  'overflow-wrap: anywhere',
  'prefers-reduced-motion: reduce'
]) {
  assert(style.includes(marker), `The China-wallet checkout CSS is missing responsive/accessibility behavior: ${marker}`);
}
assert(!/(?:url\(|@import)[^;]*https?:\/\//i.test(style), 'The checkout CSS must not depend on remote assets.');

console.log(`China-wallet QR checkout preview is provider-disabled, same-origin guarded, responsive and localized across ${supportedLocales.length} locales (${copyCallCount} copy calls).`);
