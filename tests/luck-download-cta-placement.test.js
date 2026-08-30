const assert = require('assert');
const fs = require('fs');

const template = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const css = fs.readFileSync('luck-overrides.css', 'utf8');

assert.strictEqual(
  (template.match(/id="luck-app-download"/g) || []).length,
  1,
  'the dashboard must expose exactly one resources CTA'
);
assert(
  template.includes("var downloadSection = document.querySelector('.clients-section')"),
  'the resources CTA must be anchored to the dashboard application section'
);
assert(
  template.includes("toolbar.className = 'luck-download-toolbar'") &&
    template.includes('if (download.parentElement !== toolbar) toolbar.appendChild(download)'),
  'the application section must receive one idempotent CTA toolbar'
);
assert(
  !template.includes("headerActions.insertBefore(download") &&
    !template.includes("shellActions.insertBefore(download"),
  'the resources CTA must not be injected into the global header or float over mobile content'
);
assert(
  template.includes("download.setAttribute('aria-label', localizedDownloadLabel)") &&
    template.includes("download.setAttribute('title', localizedDownloadLabel)"),
  'the compact icon CTA must retain an accessible localized label'
);

for (const label of [
  "'vi-VN': 'Tải ứng dụng'",
  "'en-US': 'Download app'",
  "'ru-RU': 'Скачать приложение'",
  "'ar': 'تنزيل التطبيق'"
]) {
  assert(template.includes(label), `download CTA locale is missing: ${label}`);
}

assert.match(
  css,
  /\.clients-section\.luck-download-section\[data-v-3709f5eb\][\s\S]*?container:\s*luck-download-section\s*\/\s*inline-size/,
  'the CTA must respond to the application card width rather than the viewport alone'
);
assert.match(
  css,
  /\.clients-section\.luck-download-section\[data-v-3709f5eb\]\s*\{[\s\S]*?display:\s*grid\s*!important[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+auto[\s\S]*?align-items:\s*center/,
  'the application title and CTA need a stable aligned grid row'
);
assert.match(
  css,
  /\.luck-download-toolbar\s*>\s*\.luck-app-download\s*\{[\s\S]*?position:\s*static\s*!important[\s\S]*?white-space:\s*nowrap\s*!important[\s\S]*?writing-mode:\s*horizontal-tb\s*!important[\s\S]*?word-break:\s*keep-all\s*!important/,
  'translated CTA labels must stay horizontal and wrap only as whole words'
);
assert.match(
  css,
  /@container luck-download-section \(max-width:\s*520px\)[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)\s+40px[\s\S]*?\.luck-app-download-label[\s\S]*?clip-path:\s*inset\(50%\)/,
  'narrow phones and foldables must switch to an accessible icon CTA instead of stacking its text'
);
assert.doesNotMatch(
  css,
  /^\s*\.luck-app-download\s*\{\s*position:\s*fixed/m,
  'the download CTA must not become a floating mobile obstruction'
);

console.log('Luck dashboard download CTA placement audit passed');
