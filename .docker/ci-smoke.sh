#!/usr/bin/env bash
set -Eeuo pipefail

image="${1:?usage: ci-smoke.sh <immutable-image-tag>}"
container_name="xboard-ci-smoke-${GITHUB_RUN_ID:-local}-$$"
install_name="${container_name}-install"
smoke_dir="$(mktemp -d)"

cleanup() {
  docker rm -f "$container_name" "$install_name" >/dev/null 2>&1 || true
  case "$smoke_dir" in
    /tmp/tmp.*) sudo rm -rf -- "$smoke_dir" >/dev/null 2>&1 || true ;;
  esac
}
trap cleanup EXIT

mkdir -p \
  "$smoke_dir/data" \
  "$smoke_dir/logs" \
  "$smoke_dir/theme" \
  "$smoke_dir/plugins" \
  "$smoke_dir/redis"
: > "$smoke_dir/.env"

common_mounts=(
  -v "$smoke_dir/.env:/www/.env"
  -v "$smoke_dir/data:/www/.docker/.data"
  -v "$smoke_dir/logs:/www/storage/logs"
  -v "$smoke_dir/theme:/www/storage/theme"
  -v "$smoke_dir/plugins:/www/plugins"
  -v "$smoke_dir/redis:/data"
)

echo "[smoke] Pulling immutable image"
docker pull "$image"

echo "[smoke] Installing a fresh SQLite instance"
if ! docker run --name "$install_name" --rm \
  -e ENABLE_SQLITE=true \
  -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=ci-smoke@example.invalid \
  -e docker=true \
  "${common_mounts[@]}" \
  "$image" php /www/artisan xboard:install --no-interaction \
  >"$smoke_dir/install.log" 2>&1; then
  sed -E 's/(password[^:]*:).*/\1 [redacted]/I' "$smoke_dir/install.log" >&2
  exit 1
fi
rm -f "$smoke_dir/install.log"

echo "[smoke] Starting the application container"
docker run --name "$container_name" -d \
  -p 127.0.0.1::7001 \
  -e ENABLE_REDIS=true \
  -e docker=true \
  "${common_mounts[@]}" \
  "$image" >/dev/null

host_port="$(docker port "$container_name" 7001/tcp | sed -n 's/.*://p' | head -n 1)"
test -n "$host_port"

health="starting"
for _ in $(seq 1 60); do
  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_name")"
  case "$health" in
    healthy) break ;;
    unhealthy)
      echo "Container healthcheck reported unhealthy." >&2
      docker inspect --format '{{range .State.Health.Log}}{{.Start}} exit={{.ExitCode}} {{printf "%q" .Output}}{{println}}{{end}}' "$container_name" >&2
      docker logs "$container_name"
      exit 1
      ;;
    missing)
      echo "Published image has no Docker healthcheck." >&2
      exit 1
      ;;
  esac
  sleep 2
done

if [ "$health" != healthy ]; then
  echo "Container did not become healthy (last status: $health)." >&2
  docker logs "$container_name" >&2
  exit 1
fi
echo "[smoke] Container healthcheck passed"

curl --fail --silent --show-error "http://127.0.0.1:${host_port}/api/v1/guest/comm/config" >/dev/null
curl --fail --silent --show-error "http://127.0.0.1:${host_port}/dashboard" >/dev/null
admin_html="$(curl --fail --silent --show-error "http://127.0.0.1:${host_port}/Huy2006")"
echo "[smoke] Public HTTP endpoints passed"

for luck_dashboard_template in \
  '/www/public/theme/Luck/dashboard.blade.php' \
  '/www/storage/theme/Luck/dashboard.blade.php'; do
  docker exec "$container_name" test -f "$luck_dashboard_template"
  for dashboard_asset_marker in \
    'id="luck-overrides-stylesheet"' \
    'luck-overrides.css?v=24' \
    'BBbuoBq5-fresh.js?v=62' \
    'i18n-v18.js?v=61' \
    'data-luck-icon="language"'; do
    docker exec "$container_name" grep -aFq "$dashboard_asset_marker" "$luck_dashboard_template" || {
      echo "Packaged Luck dashboard is missing marker $dashboard_asset_marker in $luck_dashboard_template" >&2
      exit 1
    }
  done
  if docker exec "$container_name" grep -aFq '🌐' "$luck_dashboard_template"; then
    echo "Packaged Luck dashboard still contains a platform-dependent globe glyph: $luck_dashboard_template" >&2
    exit 1
  fi
  if docker exec "$container_name" grep -aFq 'document.body.appendChild(overlay)' "$luck_dashboard_template"; then
    echo "Packaged Luck dashboard still manually reparents the Vue subscription overlay: $luck_dashboard_template" >&2
    exit 1
  fi
done
verify_packaged_luck_asset() {
  local public_file="$1"
  local storage_file="$2"
  local build_source="$3"
  local asset_url="$4"
  local http_status

  docker exec "$container_name" test -s "$public_file"
  docker exec "$container_name" test -s "$storage_file"
  docker exec "$container_name" cmp -s "$public_file" "$build_source"
  docker exec "$container_name" cmp -s "$storage_file" "$build_source"
  http_status="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$asset_url")"
  if [ "$http_status" != 200 ]; then
    echo "Packaged Luck asset returned HTTP $http_status: $asset_url" >&2
    exit 1
  fi
}

verify_packaged_luck_asset \
  '/www/public/theme/Luck/assets/luck-overrides.css' \
  '/www/storage/theme/Luck/assets/luck-overrides.css' \
  '/tmp/luck-custom/luck-overrides.css' \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/luck-overrides.css?v=24"
verify_packaged_luck_asset \
  '/www/public/theme/Luck/i18n-v18.js' \
  '/www/storage/theme/Luck/i18n-v18.js' \
  '/tmp/luck-custom/luck-i18n-v18.js' \
  "http://127.0.0.1:${host_port}/theme/Luck/i18n-v18.js?v=61"
echo "[smoke] Packaged Luck shell and maintained assets passed"

luck_entry_public='/www/public/theme/Luck/assets/BBbuoBq5-fresh.js'
luck_entry_storage='/www/storage/theme/Luck/assets/BBbuoBq5-fresh.js'
if docker exec "$container_name" test -f "$luck_entry_public"; then
  # A reusable image can be started with a pre-populated Luck volume. When the
  # complete distribution is available, keep exercising its published lazy
  # graph exactly as the production deployment gate does.
  luck_entry_js="$(curl --fail --silent --show-error "http://127.0.0.1:${host_port}/theme/Luck/assets/BBbuoBq5-fresh.js?v=62")"
subscription_dialog_asset="$(grep -oE '\./C6e3mGRa[^"?]+\.js' <<<"$luck_entry_js" | sort -u | head -n 1)"
case "$subscription_dialog_asset" in
  ./C6e3mGRa*-payment-v4.js) ;;
  *)
    echo "Published Luck entry has no normalized subscription-dialog module: $subscription_dialog_asset" >&2
    exit 1
    ;;
esac
subscription_dialog_js="$(curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${subscription_dialog_asset#./}")"
for subscription_dialog_marker in \
  'T as Teleport' \
  'name: "PortalledSubscriptionDialog"' \
  'inheritAttrs: false' \
  'createVNode(Teleport, { to: "body" }'; do
  grep -aFq "$subscription_dialog_marker" <<<"$subscription_dialog_js" || {
    echo "Published Luck subscription dialog is missing Vue Teleport marker: $subscription_dialog_marker" >&2
    exit 1
  }
done
subscription_dialog_tmp="$(mktemp --suffix=.mjs)"
printf '%s\n' "$subscription_dialog_js" >"$subscription_dialog_tmp"
node --check "$subscription_dialog_tmp"
rm -f "$subscription_dialog_tmp"
echo "[smoke] Vue-owned subscription dialog Teleport passed"

node_route_asset="$(grep -oE '\./oPGsis9D[^"?]+\.js' <<<"$luck_entry_js" | sort -u | head -n 1)"
case "$node_route_asset" in
  ./oPGsis9D*-access-v2.js) ;;
  *)
    echo "Published Luck entry has no normalized node-route module: $node_route_asset" >&2
    exit 1
    ;;
esac
node_route_js="$(curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${node_route_asset#./}")"
if grep -aFq '/flags/' <<<"$node_route_js"; then
  echo "Published Luck node route still requests the missing /flags directory." >&2
  exit 1
fi
for node_flag_marker in \
  'luck-flags.svg?v=1#${flagAssetCode}' \
  'luck-flags.svg?v=1#${mobileFlagAssetCode}' \
  'const mobileFlagCode =' \
  'toDisplayString(mobileDisplayName)' \
  '"scrollbar-props": { trigger: "none" }'; do
  grep -aFq "$node_flag_marker" <<<"$node_route_js" || {
    echo "Published Luck node route is missing portable flag marker: $node_flag_marker" >&2
    exit 1
  }
done
node_flag_host_count="$( { grep -aoF 'class: "luck-node-flag"' <<<"$node_route_js" || true; } | wc -l | tr -d '[:space:]')"
if [ "$node_flag_host_count" -lt 2 ]; then
  echo "Published Luck node route patched only $node_flag_host_count of 2 flag renderers." >&2
  exit 1
fi
echo "[smoke] Desktop and mobile lazy node-route flags passed"

orders_route_asset="$( { grep -aoE '\./lsrL0SOU[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
traffic_route_asset="$( { grep -aoE '\./BR9H_Zte[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
invite_route_asset="$( { grep -aoE '\./DSCv3-VU[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
for portable_route_asset in "$orders_route_asset" "$traffic_route_asset" "$invite_route_asset"; do
  if [ -z "$portable_route_asset" ]; then
    echo "Published Luck entry is missing a versioned portable-icon lazy route." >&2
    exit 1
  fi
done
orders_route_js="$(curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${orders_route_asset#./}")"
traffic_route_js="$(curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${traffic_route_asset#./}")"
invite_route_js="$(curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${invite_route_asset#./}")"
grep -aFq '"data-luck-icon": "orders-empty"' <<<"$orders_route_js"
grep -aFq '"data-luck-icon": "traffic-empty"' <<<"$traffic_route_js"
for invite_icon_marker in warning balance record hint; do
  grep -aFq "\"data-luck-icon\": \"${invite_icon_marker}\"" <<<"$invite_route_js" || {
    echo "Published Luck invite route is missing portable icon: $invite_icon_marker" >&2
    exit 1
  }
done
for route_platform_glyph in '📋' '📊' '⚠️' '💰' '📝' '💡'; do
  if grep -aFq "$route_platform_glyph" <<<"${orders_route_js}${traffic_route_js}${invite_route_js}"; then
    echo "Published Luck lazy routes still contain platform-dependent glyph: $route_platform_glyph" >&2
    exit 1
  fi
done
echo "[smoke] Portable dashboard, empty-state and transfer icons passed"
elif docker exec "$container_name" test -f "$luck_entry_storage"; then
  echo "Luck entry exists in the mounted theme but was not published to public/theme." >&2
  exit 1
else
  # Luck is an admin-uploaded theme and is intentionally not part of a fresh
  # Xboard database. Its patch transformations are covered by the PHP source
  # smoke below; the strict runtime graph check remains in deploy-production.
  echo "[smoke] Fresh SQLite image has no user-installed Luck distribution; production-volume lazy routes skipped"
fi

mapfile -t admin_js_paths < <({ grep -oE 'src="/assets/admin/assets/index-[^"]+\.js\?v=[^"]+"' <<<"$admin_html" || true; } | cut -d'"' -f2)
mapfile -t admin_css_paths < <({ grep -oE 'href="/assets/admin/assets/index-[^"]+\.css\?v=[^"]+"' <<<"$admin_html" || true; } | cut -d'"' -f2)
mapfile -t admin_locale_paths < <({ grep -oE 'src="/assets/admin/locales/[^"]+\.js\?v=[^"]+"' <<<"$admin_html" || true; } | cut -d'"' -f2)
admin_favicon_svg_path="$({ grep -oE 'href="/admin-favicon\.svg\?v=[^"]+"' <<<"$admin_html" || true; } | cut -d'"' -f2)"
admin_favicon_png_path="$({ grep -oE 'href="/images/favicon\.png\?v=[^"]+"' <<<"$admin_html" || true; } | cut -d'"' -f2)"
if [ "${#admin_js_paths[@]}" -ne 1 ] \
  || [ "${#admin_css_paths[@]}" -lt 1 ] \
  || [ "${#admin_locale_paths[@]}" -lt 1 ] \
  || [ -z "$admin_favicon_svg_path" ] \
  || [ -z "$admin_favicon_png_path" ]; then
  echo "Admin shell emitted an unexpected asset set: ${#admin_js_paths[@]} entry JS, ${#admin_css_paths[@]} CSS, ${#admin_locale_paths[@]} locales, SVG favicon=$admin_favicon_svg_path, PNG favicon=$admin_favicon_png_path." >&2
  exit 1
fi
admin_asset_version="$(docker exec "$container_name" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo rawurlencode((string) config("app.version", ""));
')"
test -n "$admin_asset_version"
for admin_asset_path in "${admin_js_paths[@]}" "${admin_css_paths[@]}" "${admin_locale_paths[@]}" "$admin_favicon_svg_path" "$admin_favicon_png_path"; do
  test "${admin_asset_path##*\?v=}" = "$admin_asset_version"
  admin_asset_file="/www/public${admin_asset_path%%\?*}"
  docker exec "$container_name" test -f "$admin_asset_file"
done
if [ -n "${GITHUB_SHA:-}" ]; then
  expected_admin_asset_pattern="^[0-9]{8}-${GITHUB_SHA:0:7}$"
  if ! [[ "$admin_asset_version" =~ $expected_admin_asset_pattern ]]; then
    echo "Admin asset version $admin_asset_version does not match the build-stamped image revision ${GITHUB_SHA:0:7}." >&2
    exit 1
  fi
fi
admin_js_path="${admin_js_paths[0]}"
admin_js_file="/www/public${admin_js_path%%\?*}"
docker exec "$container_name" grep -aFq 'role:"img","aria-label":"Việt Nam"' "$admin_js_file"
docker exec "$container_name" grep -aFq 'viewBox:"0 0 30 20"' "$admin_js_file"
admin_css_marker_found=false
for admin_css_path in "${admin_css_paths[@]}"; do
  admin_css_file="/www/public${admin_css_path%%\?*}"
  if docker exec "$container_name" grep -aFq 'xboard-admin-icon-visibility' "$admin_css_file"; then
    admin_css_marker_found=true
  fi
done
if [ "$admin_css_marker_found" != true ]; then
  echo "Admin stylesheets are missing the icon visibility marker." >&2
  exit 1
fi
admin_favicon_svg="$(curl --fail --silent --show-error "http://127.0.0.1:${host_port}${admin_favicon_svg_path}")"
grep -aFq 'aria-label="ZaoGuang admin"' <<<"$admin_favicon_svg"
grep -aFq 'stroke="#fff"' <<<"$admin_favicon_svg"
for admin_favicon_url in "$admin_favicon_png_path" '/images/favicon.svg'; do
  curl --fail --silent --show-error --output /dev/null "http://127.0.0.1:${host_port}${admin_favicon_url}"
done
docker exec "$container_name" php -r '
  $png = file_get_contents("/www/public/images/favicon.png");
  exit(is_string($png) && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n" ? 0 : 1);
'
echo "[smoke] Versioned admin icons and packaged favicons passed"

docker exec "$container_name" php /www/tests/smoke-order-idempotency.php
echo "[smoke] Order, payment-replay and disabled-surplus idempotency checks passed"

docker exec "$container_name" php /www/tests/smoke-plugin-admin-config.php
echo "[smoke] Plugin config, secret redaction, activation rollback and upgrade checks passed"

docker exec "$container_name" php /www/tests/smoke-custom-backend.php
echo "[smoke] CoinPayments, support-plugin, locale and Telegram checks passed"

docker exec "$container_name" php /www/tests/smoke-coinpayments-checkout-idempotency.php
echo "[smoke] CoinPayments durable checkout and uncertain-result checks passed"

docker exec "$container_name" php /www/tests/smoke-scheduler-runtime.php
echo "[smoke] Telegram schedules and encrypted database backup passed"

docker exec "$container_name" php /www/tests/smoke-node-access-url.php
echo "[smoke] Node access URL and Outline compatibility checks passed"

docker exec "$container_name" php /www/tests/smoke-luck-theme-patches.php
echo "[smoke] Luck runtime patch checks passed"

echo "[smoke] Waiting for a real scheduler heartbeat"
scheduler_runtime_ok=false
for _ in $(seq 1 30); do
  heartbeat="$({
    docker exec "$container_name" php /www/artisan tinker --execute='
      $last = (int) \Illuminate\Support\Facades\Cache::get(\App\Utils\CacheKey::get("SCHEDULE_LAST_CHECK_AT", null), 0);
      echo ($last > 0 && (time() - $last) <= 90) ? "fresh" : "stale";
    '
  } 2>/dev/null || true)"
  if grep -q 'fresh' <<<"$heartbeat"; then
    scheduler_runtime_ok=true
    break
  fi
  sleep 3
done
if [ "$scheduler_runtime_ok" != true ]; then
  echo "Dedicated scheduler did not produce a fresh runtime heartbeat." >&2
  docker logs "$container_name" >&2
  exit 1
fi
echo "[smoke] Dedicated scheduler runtime passed"

docker exec "$container_name" php /www/artisan schedule:list --no-ansi >/dev/null
echo "[smoke] Scheduler registration passed"

migration_status="$(docker exec "$container_name" php /www/artisan migrate:status --no-ansi)"
grep -q '2026_08_29_000003_enable_email_verification_and_set_admin_path.*Ran' <<<"$migration_status"
grep -q '2026_08_30_000004_create_order_payment_checkouts.*Ran' <<<"$migration_status"
if grep -q 'Pending' <<<"$migration_status"; then
  echo "One or more migrations remain pending." >&2
  printf '%s\n' "$migration_status" >&2
  exit 1
fi
echo "[smoke] Required migrations passed with no pending migration"

integrity="$(docker exec "$container_name" sqlite3 /www/.docker/.data/database.sqlite 'PRAGMA integrity_check;')"
if [ "$integrity" != "ok" ]; then
  echo "SQLite integrity check failed: $integrity" >&2
  exit 1
fi
echo "[smoke] SQLite integrity passed after runtime tests"

expected_revision="${GITHUB_SHA:-}"
if [ -n "$expected_revision" ]; then
  actual_revision="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$container_name")"
  test "$actual_revision" = "$expected_revision"
  echo "[smoke] Image revision label passed"
fi

echo "Container smoke test passed for $image"
