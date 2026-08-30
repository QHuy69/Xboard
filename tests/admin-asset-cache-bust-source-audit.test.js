const fs = require('fs');

const view = fs.readFileSync('resources/views/admin.blade.php', 'utf8');
const routes = fs.readFileSync('routes/web.php', 'utf8');
const smoke = fs.readFileSync('.docker/ci-smoke.sh', 'utf8');
const deploy = fs.readFileSync('.docker/deploy-production.sh', 'utf8');

const adminRouteStart = routes.indexOf("Route::get('/' . admin_setting('secure_path'");
const adminRouteEnd = adminRouteStart === -1 ? -1 : routes.indexOf('\n});', adminRouteStart);
const adminRoute = adminRouteEnd === -1 ? '' : routes.slice(adminRouteStart, adminRouteEnd);
const userRenderStart = routes.indexOf('$renderParams = [');
const userRenderEnd = userRenderStart === -1 ? -1 : routes.indexOf('\n        ];', userRenderStart);
const userRender = userRenderEnd === -1 ? '' : routes.slice(userRenderStart, userRenderEnd);

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(
  view.includes("$assetVersion = rawurlencode((string) config('app.version', '1.0.0'))"),
  'The admin shell does not derive its cache key directly from the build-stamped app version.'
);
assert(
  adminRoute.includes("'version' => config('app.version', '1.0.0')"),
  'The admin window settings can still expose a stale Redis-cached version.'
);
assert(
  userRender.includes("'version' => app(UpdateService::class)->getCurrentVersion()"),
  'The user theme render version was accidentally coupled to the admin cache key.'
);
assert(view.includes("$fallbackHtmlPath = public_path('assets/admin/index.html')"), 'Missing-manifest fallback does not use the distribution entry document.');
assert(view.includes('$resolveAsset = static function'), 'Admin manifest assets are not constrained to real packaged files.');
assert(view.includes("realpath(public_path('assets/admin/' . $relative))"), 'Admin manifest assets are not resolved below the packaged asset root.');
assert(!view.includes('filemtime('), 'Admin fallback can still mix independently selected builds by modification time.');
for (const missing of ['assets/index.js', 'assets/index.css', 'assets/vendor.css', 'locales/ko-KR.js']) {
  assert(!view.includes(missing), `Admin fallback still references absent file: ${missing}`);
}

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
  assert(
    source.includes('admin_asset_version') && source.includes('config("app.version", "")'),
    `The ${name} does not compare emitted URLs with the build-stamped app version.`
  );
}

assert(
  smoke.includes('expected_admin_asset_pattern="^[0-9]{8}-${GITHUB_SHA:0:7}$"'),
  'The published-image smoke does not require the exact build-stamped version format.'
);
assert(
  deploy.includes('asset_revision_short="${actual:0:7}"') &&
    deploy.includes('*-"$asset_revision_short"'),
  'The production gate does not bind admin assets to the immutable OCI revision.'
);

console.log('Admin assets are cache-busted and verified after image startup.');
