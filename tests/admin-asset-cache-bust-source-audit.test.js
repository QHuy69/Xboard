const fs = require('fs');

const view = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
const smoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(
  view.includes("$assetVersion = rawurlencode((string) ($version ?: '1'))"),
  'The admin shell does not derive its cache key from the immutable build version.'
);

for (const asset of ['$css', '$locale', '$js']) {
  assert(
    view.includes(`{{ ${asset} }}?v={{ $assetVersion }}`),
    `The admin ${asset} URL is not versioned.`
  );
}

for (const [name, source] of [['published-image smoke', smoke], ['production deploy gate', deploy]]) {
  assert(
    source.includes('index-[^\"]+\\.js\\?v=[^\"]+'),
    `The ${name} does not require a versioned admin JavaScript URL.`
  );
  assert(
    source.includes('role:\"img\",\"aria-label\":\"Việt Nam\"'),
    `The ${name} does not verify the portable Vietnamese SVG flag.`
  );
  assert(
    source.includes('xboard-admin-icon-visibility'),
    `The ${name} does not verify the responsive icon shrink guard.`
  );
}

console.log('Admin assets are cache-busted and verified after image startup.');
