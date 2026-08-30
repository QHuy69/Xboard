const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const runtimeSource = fs.readFileSync('luck-i18n-v18.js', 'utf8');
const fixture = JSON.parse(fs.readFileSync('tests/fixtures/luck-cjk-leaks.json', 'utf8'));
const audited = [
  ...fixture.user_facing,
  ...fixture.technical_or_console,
  ...fixture.map_aliases
];
const localeIds = ['en-US', 'vi-VN', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU'];

function createTranslator(locale) {
  const sandbox = {
    window: {
      LUCK_SERVER_LANGUAGES: [locale],
      LUCK_DEFAULT_LANGUAGE: locale,
      localStorage: {
        getItem(key) {
          if (key === 'luck_locale') return locale;
          if (key === 'luck_locale_manual') return '1';
          return null;
        },
        setItem() {}
      },
      location: { reload() {} }
    },
    navigator: { languages: [locale], language: locale },
    document: {
      documentElement: { lang: '', classList: { remove() {} } },
      title: '',
      head: null,
      body: null,
      cookie: `luck_locale=${locale}; luck_locale_manual=1`,
      addEventListener() {}
    },
    MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
    Intl,
    console: { log() {}, warn() {}, error() {} },
    setTimeout,
    clearTimeout
  };
  vm.runInNewContext(runtimeSource, sandbox);
  return sandbox.window.__LUCK_T__;
}

function placeholders(value) {
  return [...String(value).matchAll(/\$\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/g)]
    .map((match) => match[0])
    .sort();
}

assert.strictEqual(audited.length, 280, 'Production Luck locale fixture must cover all 280 discovered strings');
const translators = Object.fromEntries(localeIds.map((locale) => [locale, createTranslator(locale)]));

for (const locale of localeIds) {
  const failures = [];
  for (const entry of audited) {
    const english = translators['en-US'](entry.source);
    const actual = translators[locale](entry.source);
    if (!actual || actual === 'undefined' || actual === 'null') failures.push({ reason: 'empty', ...entry, actual });
    if (placeholders(actual).join('\0') !== placeholders(english).join('\0')) {
      failures.push({ reason: 'placeholder', ...entry, english, actual });
    }
    if (!['zh-CN', 'zh-TW', 'ja-JP'].includes(locale) && /[\u3400-\u9fff]/.test(actual)) {
      failures.push({ reason: 'CJK leak', ...entry, actual });
    }
    const properNameMayMatchEnglish = entry.group === 'world-map'
      || ['微信', '支付宝'].includes(entry.source);
    if (['vi-VN', 'ko-KR', 'fa-IR', 'ru-RU'].includes(locale)
      && actual === english
      && !properNameMayMatchEnglish) {
      failures.push({ reason: 'English fallback', ...entry, actual });
    }
    if (locale === 'zh-TW' && actual === english) failures.push({ reason: 'English fallback', ...entry, actual });
  }
  assert.deepStrictEqual(failures, [],
    `${locale} failed production locale coverage (${failures.length}):\n${JSON.stringify(failures.slice(0, 30), null, 2)}`);
}

for (const [source, expected] of Object.entries({
  '简体中文': {
    'vi-VN': 'Tiếng Trung giản thể',
    'zh-TW': '簡體中文',
    'ja-JP': '簡体字中国語',
    'ko-KR': '중국어 간체',
    'fa-IR': 'چینی ساده‌شده',
    'ru-RU': 'Китайский (упрощённый)'
  },
  '日本語': {
    'vi-VN': 'Tiếng Nhật',
    'zh-TW': '日文',
    'ja-JP': '日本語',
    'ko-KR': '일본어',
    'fa-IR': 'ژاپنی',
    'ru-RU': 'Японский'
  }
})) {
  for (const [locale, expectedLabel] of Object.entries(expected)) {
    assert.strictEqual(translators[locale](source), expectedLabel,
      `${locale} language selector label is incorrect for ${source}`);
  }
}

// The production fixture catches every string extracted from the currently
// published chunks. Also inspect the entire runtime dictionary so pages not
// represented by those chunks cannot silently fall back to English.
const exposedRuntimeSource = runtimeSource.replace(
  /\}\)\(\);\s*$/,
  'window.__LUCK_AUDIT_DICTIONARIES__ = dictionaries;})();'
);
assert.notStrictEqual(exposedRuntimeSource, runtimeSource, 'Unable to expose Luck dictionaries for audit');
const dictionarySandbox = {
  window: {
    LUCK_SERVER_LANGUAGES: ['en-US'],
    LUCK_DEFAULT_LANGUAGE: 'en-US',
    localStorage: { getItem() { return '1'; }, setItem() {} },
    location: { reload() {} }
  },
  navigator: { languages: ['en-US'], language: 'en-US' },
  document: {
    documentElement: { lang: '', classList: { remove() {} } },
    title: '',
    head: null,
    body: null,
    cookie: 'luck_locale=en-US; luck_locale_manual=1',
    addEventListener() {}
  },
  MutationObserver: function MutationObserver() { this.observe = function observe() {}; },
  Intl,
  console: { log() {}, warn() {}, error() {} },
  setTimeout,
  clearTimeout
};
vm.runInNewContext(exposedRuntimeSource, dictionarySandbox);
const dictionaries = dictionarySandbox.window.__LUCK_AUDIT_DICTIONARIES__;
const fullEnglish = dictionaries['en-US'];
const legitimateSharedValues = new Set([
  'Windows', 'Android', 'iOS', 'macOS', 'Linux', 'Email', 'OK', 'WeChat', 'Alipay'
]);
for (const locale of ['zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU']) {
  const target = dictionaries[locale];
  const missing = Object.keys(fullEnglish).filter((key) => !(key in target));
  const fallback = Object.keys(fullEnglish).filter((key) =>
    target[key] === fullEnglish[key] && !legitimateSharedValues.has(fullEnglish[key])
  );
  const placeholderMismatches = Object.keys(fullEnglish).filter((key) =>
    placeholders(target[key]).join('\0') !== placeholders(fullEnglish[key]).join('\0')
  );
  const cjkLeaks = ['ko-KR', 'fa-IR', 'ru-RU'].includes(locale)
    ? Object.keys(fullEnglish).filter((key) => /[\u3400-\u9fff]/.test(target[key] || ''))
    : [];
  assert.deepStrictEqual(missing, [], `${locale} full Luck dictionary has missing keys: ${JSON.stringify(missing)}`);
  assert.deepStrictEqual(fallback, [], `${locale} full Luck dictionary falls back to English: ${JSON.stringify(fallback)}`);
  assert.deepStrictEqual(placeholderMismatches, [],
    `${locale} full Luck dictionary changed placeholders: ${JSON.stringify(placeholderMismatches)}`);
  assert.deepStrictEqual(cjkLeaks, [], `${locale} full Luck dictionary leaks CJK: ${JSON.stringify(cjkLeaks)}`);
}

console.log(
  `Verified ${audited.length} production strings in all ${localeIds.length} Luck locales and `
    + `${Object.keys(fullEnglish).length} complete runtime keys in every non-Chinese locale without P0 fallback, CJK leakage or placeholder loss.`
);
