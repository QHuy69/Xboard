const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');

assert.match(
  css,
  /\.desktop-modal\[data-v-6538462c\]:has\(\.plan-comparison\[data-v-7a517f7f\]\)/,
  'purchase warning modal must be widened without affecting other dialogs'
);
assert.match(
  css,
  /\.column-title\[data-v-7a517f7f\][\s\S]*?white-space:\s*nowrap\s*!important/,
  'translated plan headings must stay on one line'
);
assert.match(
  css,
  /\.status-badge\[data-v-7a517f7f\][\s\S]*?text-transform:\s*none\s*!important/,
  'translated status badges must preserve sentence case'
);
assert.match(
  css,
  /@media \(max-width:\s*680px\)[\s\S]*?\.comparison-container\[data-v-7a517f7f\][\s\S]*?flex-direction:\s*column\s*!important/,
  'plan comparison must stack on narrow screens'
);

console.log('Verified Luck purchase warning modal layout overrides.');
