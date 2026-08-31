const assert = require('assert');
const fs = require('fs');
const { spawnSync } = require('child_process');

const caddyFiles = [
  '.docker/caddy/Caddyfile',
  '.docker/caddy/Caddyfile.split'
];
const beginMarker = '# BEGIN XBOARD THEME PROTECTION';
const endMarker = '# END XBOARD THEME PROTECTION';

function normalizedProtectionBlock(file) {
  const source = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
  const begin = source.indexOf(beginMarker);
  const end = source.indexOf(endMarker, begin + beginMarker.length);

  assert(begin >= 0, `${file} is missing ${beginMarker}`);
  assert(end > begin, `${file} is missing ${endMarker}`);
  assert.strictEqual(
    source.indexOf(beginMarker, begin + beginMarker.length),
    -1,
    `${file} contains more than one theme-protection block`
  );
  assert.strictEqual(
    source.indexOf(endMarker, end + endMarker.length),
    -1,
    `${file} contains more than one theme-protection end marker`
  );

  return source.slice(begin, end + endMarker.length).trim();
}

const blocks = caddyFiles.map((file) => ({
  file,
  block: normalizedProtectionBlock(file)
}));

assert.strictEqual(
  blocks[0].block,
  blocks[1].block,
  'Embedded and split Caddy deployments do not enforce the exact same theme-protection policy'
);

const policy = blocks[0].block;
for (const required of [
  '@theme_cross_site',
  'path /theme/*',
  'header Sec-Fetch-Site cross-site',
  'respond @theme_cross_site 403',
  '@theme_root',
  'respond @theme_root 404',
  '@theme_directory',
  'respond @theme_directory 404',
  '@theme_sensitive_source',
  'respond @theme_sensitive_source 404',
  '@theme_dotfile',
  'respond @theme_dotfile 404',
  '@theme_assets',
  'Cross-Origin-Resource-Policy "same-origin"',
  'Content-Security-Policy "frame-ancestors \'self\'"',
  'X-Frame-Options "SAMEORIGIN"',
  'X-Content-Type-Options "nosniff"'
]) {
  assert(policy.includes(required), `Theme-protection policy is missing: ${required}`);
}

for (const sensitiveName of [
  'dashboard\\.blade\\.php',
  'config\\.json',
  'index\\.html',
  'env',
  'map',
  'vue',
  'ts',
  'tsx',
  'scss'
]) {
  assert(policy.includes(sensitiveName), `Sensitive theme path is not covered: ${sensitiveName}`);
}

assert(
  /header\s+@theme_assets\s*\{[\s\S]*?Cross-Origin-Resource-Policy\s+"same-origin"[\s\S]*?\}/.test(policy),
  'CORP same-origin is not scoped to browser-required theme assets'
);
assert(
  /header\s*\{[\s\S]*?Content-Security-Policy\s+"frame-ancestors 'self'"[\s\S]*?X-Frame-Options\s+"SAMEORIGIN"[\s\S]*?X-Content-Type-Options\s+"nosniff"[\s\S]*?\}/.test(policy),
  'Global anti-framing and MIME-sniffing response headers are incomplete'
);

const smoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
for (const runtimeGuard of [
  '[smoke] Validating packaged Caddy syntax',
  '/etc/caddy/Caddyfile.split',
  '[smoke] Theme source and cross-site guards passed',
  'theme-protection-sentinel.js.map',
  'Sec-Fetch-Site: cross-site',
  'Sec-Fetch-Site: same-origin',
  'Content-Security-Policy',
  'X-Frame-Options',
  'X-Content-Type-Options',
  'Cross-Origin-Resource-Policy'
]) {
  assert(smoke.includes(runtimeGuard), `Published-image smoke is missing theme guard: ${runtimeGuard}`);
}

const caddyVersion = spawnSync('caddy', ['version'], { encoding: 'utf8' });
if (!caddyVersion.error && caddyVersion.status === 0) {
  for (const file of caddyFiles) {
    const validation = spawnSync(
      'caddy',
      ['validate', '--config', file, '--adapter', 'caddyfile'],
      { encoding: 'utf8' }
    );
    assert.strictEqual(
      validation.status,
      0,
      `Caddy rejected ${file}:\n${validation.stdout || ''}${validation.stderr || ''}`
    );
  }
  console.log('Validated both Caddyfiles with the available Caddy binary.');
} else {
  console.log('Caddy binary is unavailable; published-image smoke will validate the packaged config.');
}

console.log('Embedded and split deployments share one audited theme-protection policy.');
