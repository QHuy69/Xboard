const assert = require('assert');
const fs = require('fs');

const dockerfile = fs.readFileSync('Dockerfile', 'utf8');
const entrypoint = fs.readFileSync('.docker/entrypoint.sh', 'utf8');
const ciSmoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');
const flags = fs.readFileSync('luck-flags.svg', 'utf8');

assert(
  dockerfile.includes(
    'COPY luck-i18n-v18.js luck-dashboard.blade.php luck-overrides.css luck-donate-qr.svg luck-clash.svg luck-flags.svg /tmp/luck-custom/'
  ),
  'Dockerfile must package the maintained Luck icon assets in /tmp/luck-custom'
);

assert(
  dockerfile.includes(
    'cp /tmp/luck-custom/luck-clash.svg public/theme/Luck/assets/luck-clash.svg'
  ),
  'Dockerfile must publish the Luck Clash icon into the image public theme tree'
);

assert(
  dockerfile.includes(
    'cp /tmp/luck-custom/luck-clash.svg storage/theme/Luck/assets/luck-clash.svg'
  ),
  'Dockerfile must publish the Luck Clash icon into the image storage theme tree'
);

assert(
  entrypoint.includes(
    'cp /tmp/luck-custom/luck-clash.svg "$luck_root/assets/luck-clash.svg"'
  ),
  'Entrypoint must restore the Luck Clash icon after xboard:update refreshes a persistent theme volume'
);

for (const destination of [
  'public/theme/Luck/assets/luck-flags.svg',
  'storage/theme/Luck/assets/luck-flags.svg'
]) {
  assert(
    dockerfile.includes(`cp /tmp/luck-custom/luck-flags.svg ${destination}`),
    `Dockerfile must publish the local flag sprite to ${destination}`
  );
}

assert(
  entrypoint.includes('cp /tmp/luck-custom/luck-flags.svg "$luck_root/assets/luck-flags.svg"'),
  'Entrypoint must restore the local flag sprite after xboard:update'
);

for (const id of ['un', 'jp', 'vn', 'us', 'hk', 'sg', 'kr', 'tw', 'cn']) {
  assert(flags.includes(`<symbol id="${id}"`), `local flag sprite is missing #${id}`);
}
assert(!/(?:href|src)=["']https?:\/\//.test(flags), 'local flag sprite must not depend on a third-party host');

const updatePosition = entrypoint.indexOf('php /www/artisan xboard:update --no-interaction');
const restorePosition = entrypoint.indexOf(
  'cp /tmp/luck-custom/luck-clash.svg "$luck_root/assets/luck-clash.svg"'
);
const flagRestorePosition = entrypoint.indexOf(
  'cp /tmp/luck-custom/luck-flags.svg "$luck_root/assets/luck-flags.svg"'
);

assert(updatePosition >= 0, 'Entrypoint xboard:update call is missing');
assert(
  restorePosition > updatePosition,
  'Luck Clash icon restoration must run after xboard:update can replace the public theme tree'
);
assert(
  flagRestorePosition > updatePosition,
  'Luck flag sprite restoration must run after xboard:update can replace the public theme tree'
);

for (const asset of ['luck-clash.svg', 'luck-flags.svg?v=1']) {
  assert(
    deploy.includes(`/theme/Luck/assets/${asset}`),
    `Production deployment must verify the packaged icon asset ${asset}`
  );
}

for (const packagedTemplate of [
  "'/www/public/theme/Luck/dashboard.blade.php'",
  "'/www/storage/theme/Luck/dashboard.blade.php'"
]) {
  assert(
    ciSmoke.includes(packagedTemplate),
    `Fresh-image smoke must inspect the packaged template ${packagedTemplate}`
  );
}
assert(
  !ciSmoke.includes('dashboard_html='),
  'Fresh-image smoke must not assume the installed account has selected the Luck theme'
);
assert(
  ciSmoke.includes('docker exec "$container_name" grep -aFq "$dashboard_asset_marker" "$luck_dashboard_template"'),
  'Fresh-image smoke must validate Luck markers inside the packaged template'
);
for (const packagedAssetGuard of [
  'docker exec "$container_name" test -s "$public_file"',
  'docker exec "$container_name" test -s "$storage_file"',
  'docker exec "$container_name" cmp -s "$public_file" "$build_source"',
  'docker exec "$container_name" cmp -s "$storage_file" "$build_source"',
  'Packaged Luck asset returned HTTP $http_status: $asset_url'
]) {
  assert(
    ciSmoke.includes(packagedAssetGuard),
    `Fresh-image smoke is missing packaged asset guard: ${packagedAssetGuard}`
  );
}
const optionalRuntimeStart = ciSmoke.indexOf(
  'if docker exec "$container_name" test -f "$luck_entry_public"; then'
);
const runtimeFetch = ciSmoke.indexOf(
  'luck_entry_js="$(curl --fail --silent --show-error'
);
const missingDistributionBranch = ciSmoke.indexOf(
  'Fresh SQLite image has no user-installed Luck distribution; production-volume lazy routes skipped'
);
assert(
  optionalRuntimeStart >= 0 && runtimeFetch > optionalRuntimeStart && missingDistributionBranch > runtimeFetch,
  'Fresh-image smoke must only inspect the generated Luck graph when the user-installed distribution exists'
);
assert(
  ciSmoke.includes('elif docker exec "$container_name" test -f "$luck_entry_storage"; then'),
  'Fresh-image smoke must fail if a mounted Luck entry was not published to public/theme'
);

console.log('Luck packaged icons survive image build, persistent theme mounts and xboard:update refreshes.');
