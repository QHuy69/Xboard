const assert = require('assert');
const fs = require('fs');

const route = fs.readFileSync('routes/web.php', 'utf8');
const view = fs.readFileSync('resources/views/payment/banking.blade.php', 'utf8');
const supportedLocales = ['vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

for (const locale of supportedLocales) {
  assert(route.includes(`'${locale}'`), `VietQR route does not accept ${locale}`);
}
assert(route.indexOf("preg_match('/^zh-(?:tw|hk|mo|hant)") < route.indexOf("str_starts_with($normalized, 'zh')"),
  'Traditional Chinese variants must be resolved before the generic Chinese fallback');
assert(route.includes("'fa' => 'fa-IR'"), 'VietQR route does not resolve Persian browser locales');
assert(route.includes("'ru' => 'ru-RU'"), 'VietQR route does not resolve Russian browser locales');

for (const marker of [
  "$isTw = $locale === 'zh-TW'",
  "$isFa = $locale === 'fa-IR'",
  "$isRu = $locale === 'ru-RU'",
  '$isTw => $tw',
  '$isFa => $fa',
  '$isRu => $ru',
  'dir="{{ $locale === \'fa-IR\' ? \'rtl\' : \'ltr\' }}"',
  'overflow-wrap: anywhere',
  'unicode-bidi: plaintext',
  '<bdi>{{ $order->trade_no }}</bdi>',
  '剩餘付款時間',
  'زمان باقی‌مانده برای پرداخت',
  'Оставшееся время для оплаты'
]) {
  assert(view.includes(marker), `VietQR view is missing multilocale/RTL behavior: ${marker}`);
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
    if (character === "'" || character === '"') {
      quote = character;
    } else if (character === '(') {
      depth += 1;
    } else if (character === ')') {
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
  throw new Error('Unterminated $text() call in VietQR view');
}

let callCount = 0;
for (let index = view.indexOf('$text('); index !== -1; index = view.indexOf('$text(', index + 6)) {
  const argumentsList = topLevelArguments(view, index + '$text'.length);
  assert.strictEqual(argumentsList.length, supportedLocales.length,
    `VietQR $text() call at offset ${index} has ${argumentsList.length} locale values instead of ${supportedLocales.length}`);
  for (const localeIndex of [3, 6, 7]) {
    assert.notStrictEqual(argumentsList[localeIndex], argumentsList[1],
      `VietQR $text() call at offset ${index} falls back to English for ${supportedLocales[localeIndex]}`);
  }
  callCount += 1;
}
assert(callCount >= 19, `Expected the full VietQR copy surface, found only ${callCount} translated calls`);

console.log(`VietQR resolves and renders ${supportedLocales.length} locales across ${callCount} complete text calls with Persian RTL isolation.`);
