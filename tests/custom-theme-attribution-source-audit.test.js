const assert = require('assert');
const fs = require('fs');

const notice = fs.readFileSync('CUSTOM-THEME-NOTICE.md', 'utf8');
const readme = fs.readFileSync('README.md', 'utf8');
const dashboard = fs.readFileSync('luck-dashboard.blade.php', 'utf8');
const translations = fs.readFileSync('luck-i18n-v18.js', 'utf8');
const overrides = fs.readFileSync('luck-overrides.css', 'utf8');

for (const marker of [
  'ZaoGuang-specific integration layer',
  'Copyright (c) 2026 ZaoGuang VPN',
  'does not claim ownership of Xboard, Luck'
]) {
  assert(notice.includes(marker), `custom theme notice is missing: ${marker}`);
}

assert(
  readme.includes('[CUSTOM-THEME-NOTICE.md](./CUSTOM-THEME-NOTICE.md)'),
  'README must distinguish the custom theme notice from the upstream MIT license'
);

assert(
  dashboard.includes('<meta name="author" content="ZaoGuang VPN">'),
  'Luck dashboard must publish the ZaoGuang author metadata'
);
assert(
  dashboard.includes('<meta name="copyright" content="Copyright (c) 2026 ZaoGuang VPN">'),
  'Luck dashboard must publish the ZaoGuang copyright metadata'
);

for (const [name, source] of [
  ['Luck dashboard', dashboard],
  ['Luck translations', translations],
  ['Luck responsive overrides', overrides]
]) {
  assert(
    source.includes('ZaoGuang VPN custom Luck integration layer.'),
    `${name} must retain the ZaoGuang customization attribution`
  );
}

console.log('ZaoGuang custom theme attribution is present without relicensing upstream components.');
