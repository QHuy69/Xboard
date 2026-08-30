const assert = require('assert');
const fs = require('fs');

const view = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
const dockerfile = fs.readFileSync('Dockerfile', 'utf8');
const staticAdminEntry = fs.readFileSync('public/assets/admin/index.html', 'utf8');
const smoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');
const svg = fs.readFileSync('admin-favicon.svg', 'utf8');
const png = Buffer.from(fs.readFileSync('admin-favicon.png.b64', 'utf8').trim(), 'base64');

assert.match(svg, /viewBox="0 0 64 64"/, 'Admin SVG favicon needs a square scalable viewBox.');
assert.match(svg, /aria-label="ZaoGuang admin"/, 'Admin SVG favicon is missing its brand marker.');
assert.match(svg, /stroke="#fff"/, 'Admin SVG favicon has no deterministic high-contrast foreground.');
assert(!/(?:href|xlink:href)="https?:/i.test(svg), 'Admin SVG favicon must not depend on a remote image.');

assert(png.length >= 256, 'Admin PNG favicon payload is unexpectedly small.');
assert.strictEqual(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a', 'Admin PNG favicon has an invalid signature.');
assert.strictEqual(png.readUInt32BE(16), 64, 'Admin PNG favicon must be 64px wide.');
assert.strictEqual(png.readUInt32BE(20), 64, 'Admin PNG favicon must be 64px high.');

for (const marker of [
  'href="/admin-favicon.svg?v={{ $assetVersion }}"',
  'href="/images/favicon.png?v={{ $assetVersion }}"',
]) {
  assert(view.includes(marker), `Admin shell is missing versioned favicon marker: ${marker}`);
}
assert(
  view.indexOf('href="/admin-favicon.svg?v={{ $assetVersion }}"') > view.indexOf('$assetVersion = rawurlencode'),
  'Admin favicon is emitted before its immutable build-version key is defined.'
);

for (const legacyPath of ['/images/favicon.svg', '/images/favicon.png']) {
  assert(staticAdminEntry.includes(`href="${legacyPath}"`), `Unexpected admin distribution favicon path: ${legacyPath}`);
}
for (const marker of [
  'COPY admin-favicon.svg /www/public/admin-favicon.svg',
  'COPY admin-favicon.png.b64 /tmp/admin-favicon.png.b64',
  'cp /www/public/admin-favicon.svg /www/public/images/favicon.svg',
  'base64 -d /tmp/admin-favicon.png.b64 > /www/public/images/favicon.png',
]) {
  assert(dockerfile.includes(marker), `Docker image does not package admin favicon marker: ${marker}`);
}

for (const [name, source] of [['published-image smoke', smoke], ['production deploy gate', deploy]]) {
  for (const marker of [
    'href="/admin-favicon\\.svg\\?v=[^"]+"',
    'href="/images/favicon\\.png\\?v=[^"]+"',
    '"$admin_favicon_svg_path" "$admin_favicon_png_path"',
    "'/images/favicon.svg'",
    'aria-label="ZaoGuang admin"',
    'substr($png, 0, 8) === "\\x89PNG\\r\\n\\x1a\\n"',
  ]) {
    assert(source.includes(marker), `${name} does not verify packaged favicon marker: ${marker}`);
  }
}

console.log('Admin favicon is local, versioned, packaged and release-gated.');
