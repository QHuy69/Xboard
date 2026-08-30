const assert = require('assert');
const fs = require('fs');

const css = fs.readFileSync('luck-overrides.css', 'utf8');

function expect(pattern, message) {
  assert.match(css, pattern, message);
}

expect(
  /\.tickets-table\[data-v-7de65899\] \.n-data-table-wrapper\s*\{[\s\S]*?overflow-x:\s*scroll\s*!important[\s\S]*?scrollbar-gutter:\s*stable/,
  'ticket history must have one immediate horizontal scroll owner'
);
expect(
  /\.tickets-table\[data-v-7de65899\] \.n-data-table-base-table\s*\{[\s\S]*?width:\s*max\(100%,\s*1020px\)\s*!important[\s\S]*?min-width:\s*1020px\s*!important/,
  'ticket history must preserve the declared width of its seven columns'
);
expect(
  /\.tickets-table\[data-v-7de65899\] \.n-scrollbar-rail--horizontal\s*\{[\s\S]*?display:\s*none\s*!important/,
  'ticket history must hide the inner Naive rail to avoid double scrollbars'
);
expect(
  /\.tickets-table\[data-v-7de65899\] \.n-data-table-wrapper::-webkit-scrollbar\s*\{[\s\S]*?height:\s*11px/,
  'ticket history must expose a visibly sized WebKit rail'
);
expect(
  /\.tickets-table\[data-v-7de65899\] \.n-data-table-wrapper::-webkit-scrollbar-thumb\s*\{[\s\S]*?min-width:\s*40px[\s\S]*?background:\s*rgba\(71,\s*85,\s*105,\s*\.72\)/,
  'ticket history scrollbar must have a visible usable thumb'
);

expect(
  /@media \(min-width:\s*769px\)[\s\S]*?\.desktop-table\[data-v-88d3a2ea\]\s*\{[\s\S]*?overflow-x:\s*scroll\s*!important[\s\S]*?scrollbar-gutter:\s*stable/,
  'desktop traffic history must own horizontal scrolling from the 769px breakpoint'
);
expect(
  /@media \(min-width:\s*769px\)[\s\S]*?\.desktop-table\[data-v-88d3a2ea\] \.traffic-table\[data-v-88d3a2ea\]\s*\{[\s\S]*?width:\s*max\(100%,\s*640px\)\s*!important[\s\S]*?min-width:\s*640px\s*!important/,
  'desktop traffic history must retain enough width for all five columns'
);
expect(
  /@media \(min-width:\s*769px\)[\s\S]*?\.desktop-table\[data-v-88d3a2ea\] \.traffic-table\[data-v-88d3a2ea\] \.n-scrollbar-rail--horizontal\s*\{[\s\S]*?display:\s*none\s*!important/,
  'desktop traffic history must suppress a duplicate Naive horizontal rail'
);
expect(
  /@media \(max-width:\s*768px\)[\s\S]*?\.desktop-table\[data-v-88d3a2ea\]\s*\{[\s\S]*?overflow-x:\s*hidden\s*!important[\s\S]*?\.desktop-table\[data-v-88d3a2ea\] \.traffic-table\[data-v-88d3a2ea\]\s*\{[\s\S]*?min-width:\s*0\s*!important/,
  'the mobile traffic-card breakpoint must remove the desktop scrolling contract'
);

expect(
  /html,\s*\nbody\s*\{[\s\S]*?max-width:\s*100vw[\s\S]*?overflow-x:\s*clip/,
  'table scroll owners must not restore page-level horizontal overflow'
);

const viewportMatrix = [
  { width: 3440, tickets: 'fit-or-scroll', traffic: 'desktop' },
  { width: 1440, tickets: 'fit-or-scroll', traffic: 'desktop' },
  { width: 1024, tickets: 'scroll', traffic: 'desktop' },
  { width: 820, tickets: 'scroll', traffic: 'desktop-scroll-safe' },
  { width: 768, tickets: 'scroll', traffic: 'cards' },
  { width: 430, tickets: 'scroll', traffic: 'cards' },
  { width: 320, tickets: 'scroll', traffic: 'cards' }
];

for (const viewport of viewportMatrix) {
  const trafficMode = viewport.width <= 768
    ? 'cards'
    : (viewport.width <= 1000 ? 'desktop-scroll-safe' : 'desktop');
  assert.strictEqual(trafficMode, viewport.traffic, `${viewport.width}px traffic mode is incorrect`);

  if (viewport.width < 1020) {
    assert.strictEqual(viewport.tickets, 'scroll', `${viewport.width}px ticket history must be scrollable`);
  }
}

console.log('Verified isolated ticket and traffic table scrolling without page-level overflow.');
