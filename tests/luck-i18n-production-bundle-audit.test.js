const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

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
    title: '',
    head: null,
    body: null,
    cookie: '',
    addEventListener() {}
  },
  MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
  Intl,
  console,
  setTimeout,
  clearTimeout
};

const runtimeSource = fs.readFileSync('luck-i18n-v18.js', 'utf8');
const fixture = JSON.parse(fs.readFileSync('tests/fixtures/luck-cjk-leaks.json', 'utf8'));
vm.runInNewContext(runtimeSource, sandbox);

const translate = sandbox.window.__LUCK_T__;
const audited = [
  ...fixture.user_facing,
  ...fixture.technical_or_console,
  ...fixture.map_aliases
];

assert.strictEqual(
  audited.length,
  fixture.metadata.counts.user_facing
    + fixture.metadata.counts.technical_or_console
    + fixture.metadata.counts.map_aliases,
  'Luck production CJK fixture counts are inconsistent'
);

const seen = new Set();
const cjkLeaks = [];
const joinedWords = [];
const genericFallbacks = [];
const genericFallbackMessages = new Set([
  'Đang xử lý dữ liệu...',
  'Vui lòng kiểm tra thông tin bắt buộc',
  'Thao tác đã hoàn tất',
  'Thao tác thất bại, vui lòng thử lại',
  'Thông báo hệ thống'
]);
const joinedVietnamesePhrase = /(?:Xác nhận|Số dư|Nạp tiền|Đơn hàng|Gói|Thanh toán|Thông tin|Mật khẩu|Đăng ký|Mã QR|Dịch vụ|Tốc độ|Mua|Hủy|Đang|Máy chủ|Trạng thái|Gia hạn|Đã thanh toán|Hỗ trợ|Tải|Chi tiết|Làm mới|Đặt lại|Email|Mã xác minh)(?=\p{L})/u;
const joinedLowerUpper = /\p{Ll}\p{Lu}/u;
const joinedInterpolationUnit = /\}\p{L}/u;

function hasJoinedWords(value) {
  // Ignore identifiers interpolated by the production bundles and established
  // camel-cased product names. Lowercase-to-uppercase transitions elsewhere
  // are fragments such as `MạngLỗi`; the phrase guard also catches joins such
  // as `Đăng kýthất bại`, where both words start in lowercase.
  const prose = value
    .replace(/\$\{[^}]*\}/g, '')
    .replace(/\b(?:HiddifyNext|SingBox|V2rayN|WeChat|macOS|iOS)\b/g, '');
  return joinedVietnamesePhrase.test(prose)
    || joinedLowerUpper.test(prose)
    || joinedInterpolationUnit.test(value);
}

for (const entry of audited) {
  const identity = `${entry.file}\0${entry.source}`;
  assert(!seen.has(identity), `Duplicate Luck production source case: ${entry.file}: ${entry.source}`);
  seen.add(identity);

  const actual = translate(entry.source);
  if (/[\u3400-\u9fff]/.test(actual)) cjkLeaks.push({ ...entry, actual });
  if (hasJoinedWords(actual)) joinedWords.push({ ...entry, actual });
  if (genericFallbackMessages.has(actual.trim())) genericFallbacks.push({ ...entry, actual });
}

assert.strictEqual(
  cjkLeaks.length,
  0,
  `Luck production strings still leak CJK (${cjkLeaks.length}):\n${JSON.stringify(cjkLeaks.slice(0, 30), null, 2)}`
);
assert.strictEqual(
  joinedWords.length,
  0,
  `Luck production strings still join Vietnamese words (${joinedWords.length}):\n${JSON.stringify(joinedWords.slice(0, 30), null, 2)}`
);
assert.strictEqual(
  genericFallbacks.length,
  0,
  `Luck production strings still use generic Vietnamese fallbacks (${genericFallbacks.length}):\n${JSON.stringify(genericFallbacks.slice(0, 30), null, 2)}`
);

console.log(
  `Verified ${audited.length} production Luck strings against the vi-VN runtime: `
    + `${cjkLeaks.length} CJK leaks, ${joinedWords.length} joined words, `
    + `${genericFallbacks.length} generic fallbacks.`
);
