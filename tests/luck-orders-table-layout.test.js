const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const css = fs.readFileSync('luck-overrides.css', 'utf8');

assert.match(
  css,
  /\.orders-table-container\[data-v-95571e5b\][\s\S]*?overflow-x:\s*scroll\s*!important/,
  'the order ledger container must keep a visible horizontal scrollbar in table mode'
);
assert.match(
  css,
  /\.ios-content-container:has\(\.orders-page\[data-v-95571e5b\]\),[\s\S]*?height:\s*100%\s*!important[\s\S]*?min-height:\s*0\s*!important/,
  'the orders route must use the shell available height instead of extending its rail below the viewport'
);
assert.match(
  css,
  /\.orders-page\[data-v-95571e5b\]\s*\{[\s\S]*?height:\s*100%\s*!important[\s\S]*?max-height:\s*100%\s*!important/,
  'the orders page must remain bounded by the visible shell'
);
assert.match(
  css,
  /\.orders-page\[data-v-95571e5b\]\s*\{[\s\S]*?width:\s*100%\s*!important[\s\S]*?max-width:\s*none\s*!important[\s\S]*?margin-inline:\s*0\s*!important/,
  'wide order ledgers must expand into all available content space before horizontal scrolling is used'
);
assert.match(
  css,
  /\.orders-table-container\[data-v-95571e5b\]::-(?:webkit|moz)-scrollbar|\.orders-table-container\[data-v-95571e5b\]::-webkit-scrollbar/,
  'the order ledger scrollbar must remain visibly styled'
);
assert.match(
  css,
  /@media \(min-width:\s*769px\)[\s\S]*?\.orders-table\[data-v-95571e5b\][\s\S]*?min-width:\s*1180px\s*!important/,
  'tablet and desktop ledgers must reserve enough width for all seven columns and the three-button action group'
);

const desktopGridRules = css.match(
  /\.orders-table\[data-v-95571e5b\] \.table-header\[data-v-95571e5b\],[\s\S]*?grid-template-columns:\s*([^;]+)!important;/
);
assert.ok(desktopGridRules, 'the header and rows must share one explicit grid definition');
assert.strictEqual(
  desktopGridRules[1].trim().split(/\s+(?![^()]*\))/).length,
  7,
  'the shared order grid must define exactly seven columns'
);
assert.match(
  css,
  /\.order-number\[data-v-95571e5b\][\s\S]*?word-break:\s*normal\s*!important[\s\S]*?overflow-wrap:\s*normal\s*!important/,
  'order numbers must never wrap one character per line'
);
assert.match(
  css,
  /\.order-number\[data-v-95571e5b\],[\s\S]*?white-space:\s*nowrap\s*!important/,
  'order numbers, amounts, statuses and timestamps must remain on readable lines'
);
assert.match(
  css,
  /\.actions\[data-v-95571e5b\][\s\S]*?align-items:\s*center\s*!important[\s\S]*?justify-content:\s*center\s*!important/,
  'action buttons must align with their header and remain inside the last column'
);
assert.match(
  css,
  /\.actions\[data-v-95571e5b\][\s\S]*?min-width:\s*220px\s*!important[\s\S]*?flex-wrap:\s*nowrap\s*!important/,
  'the action column must keep detail, payment and cancel buttons on one non-overlapping row'
);
assert.match(
  css,
  /\.actions\[data-v-95571e5b\]\s*>\s*\.btn-base\[data-v-95571e5b\][\s\S]*?min-width:\s*max-content\s*!important[\s\S]*?white-space:\s*nowrap\s*!important/,
  'localized action labels must keep their intrinsic width instead of being clipped'
);
assert.match(
  css,
  /@media \(max-width:\s*768px\)[\s\S]*?\.orders-table\[data-v-95571e5b\][\s\S]*?min-width:\s*0\s*!important/,
  'phones and foldables must reset the desktop minimum width for card layout'
);

const viewportMatrix = [
  { name: 'ultrawide', width: 2560, mode: 'grid' },
  { name: 'desktop 16:9', width: 1920, mode: 'grid' },
  { name: 'desktop 16:10', width: 1440, mode: 'grid' },
  { name: 'narrow Windows window', width: 900, mode: 'scrollable-grid' },
  { name: 'iPad landscape', width: 1024, mode: 'scrollable-grid' },
  { name: 'iPad portrait', width: 820, mode: 'scrollable-grid' },
  { name: 'foldable', width: 540, mode: 'cards' },
  { name: 'large phone', width: 430, mode: 'cards' },
  { name: 'small phone', width: 320, mode: 'cards' }
];
viewportMatrix.forEach(({ name, width, mode }) => {
  const resolvedMode = width <= 768 ? 'cards' : (width < 1180 ? 'scrollable-grid' : 'grid');
  assert.strictEqual(resolvedMode, mode, `${name} must resolve to the expected order layout`);
});

for (const localeSelector of ['zh-CN', 'zh-TW', 'lang^="ja"', 'lang^="ko"', 'lang^="fa"', 'lang^="ru"']) {
  assert(css.includes(localeSelector), `mobile order-card labels are missing ${localeSelector}`);
}
assert.match(css, /\.order-info\[data-v-95571e5b\],[\s\S]*?text-align:\s*start\s*!important/, 'order content must follow LTR/RTL logical alignment');
assert.match(css, /\.amount-info\[data-v-95571e5b\][\s\S]*?text-align:\s*end\s*!important/, 'amount content must follow LTR/RTL logical alignment');

const sandbox = {
  window: {
    LUCK_SERVER_LANGUAGES: ['vi-VN'],
    LUCK_DEFAULT_LANGUAGE: 'vi-VN',
    localStorage: { getItem() { return null; }, setItem() {} },
    location: { reload() {} }
  },
  navigator: { languages: ['vi-VN'], language: 'vi-VN' },
  document: {
    documentElement: { lang: '', classList: { remove() {} } },
    title: '', head: null, body: null, cookie: '', addEventListener() {}
  },
  MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
  Intl,
  console,
  setTimeout,
  clearTimeout
};
vm.runInNewContext(fs.readFileSync('luck-i18n-v18.js', 'utf8'), sandbox);
const translate = sandbox.window.__LUCK_T__;
assert.strictEqual(translate('订单信息'), 'Mã đơn hàng');
assert.strictEqual(translate('Đơn hàngthông tin'), 'Mã đơn hàng');
assert.strictEqual(translate('Đơn hàngThông tin'), 'Mã đơn hàng');

console.log('Verified Luck order ledger scrolling, alignment, responsive matrix and Vietnamese header copy.');
