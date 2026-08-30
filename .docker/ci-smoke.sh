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

luck_entry_js="$(curl --fail --silent --show-error "http://127.0.0.1:${host_port}/theme/Luck/assets/BBbuoBq5-fresh.js?v=60")"
node_route_asset="$(grep -oE '\./oPGsis9D[^"?]+\.js' <<<"$luck_entry_js" | sort -u | head -n 1)"
case "$node_route_asset" in
  ./oPGsis9D*-access-v2.js) ;;
  *)
    echo "Published Luck entry has no normalized node-route module: $node_route_asset" >&2
    exit 1
    ;;
esac
curl --fail --silent --show-error \
  "http://127.0.0.1:${host_port}/theme/Luck/assets/${node_route_asset#./}" >/dev/null
echo "[smoke] Lazy node-route module passed"

admin_js_path="$(grep -oE 'src="/assets/admin/assets/index-[^"]+\.js\?v=[^"]+"' <<<"$admin_html" | cut -d'"' -f2)"
admin_css_path="$(grep -oE 'href="/assets/admin/assets/index-[^"]+\.css\?v=[^"]+"' <<<"$admin_html" | cut -d'"' -f2)"
test -n "$admin_js_path"
test -n "$admin_css_path"
admin_asset_version="$(docker exec "$container_name" php -r '
  require "/www/vendor/autoload.php";
  $app = require "/www/bootstrap/app.php";
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  echo rawurlencode((string) config("app.version", ""));
')"
test -n "$admin_asset_version"
test "${admin_js_path##*\?v=}" = "$admin_asset_version"
test "${admin_css_path##*\?v=}" = "$admin_asset_version"
if [ -n "${GITHUB_SHA:-}" ]; then
  expected_admin_asset_pattern="^[0-9]{8}-${GITHUB_SHA:0:7}$"
  if ! [[ "$admin_asset_version" =~ $expected_admin_asset_pattern ]]; then
    echo "Admin asset version $admin_asset_version does not match the build-stamped image revision ${GITHUB_SHA:0:7}." >&2
    exit 1
  fi
fi
admin_js_file="/www/public${admin_js_path%%\?*}"
admin_css_file="/www/public${admin_css_path%%\?*}"
docker exec "$container_name" grep -aFq 'role:"img","aria-label":"Việt Nam"' "$admin_js_file"
docker exec "$container_name" grep -aFq 'viewBox:"0 0 30 20"' "$admin_js_file"
docker exec "$container_name" grep -aFq 'xboard-admin-icon-visibility' "$admin_css_file"
echo "[smoke] Versioned admin SVG icon assets passed"

docker exec "$container_name" php /www/tests/smoke-order-idempotency.php
echo "[smoke] Order, payment-replay and disabled-surplus idempotency checks passed"

docker exec "$container_name" php /www/tests/smoke-plugin-admin-config.php
echo "[smoke] Plugin config, secret redaction, activation rollback and upgrade checks passed"

docker exec "$container_name" php /www/tests/smoke-custom-backend.php
echo "[smoke] CoinPayments, support-plugin, locale and Telegram checks passed"

docker exec "$container_name" php /www/tests/smoke-coinpayments-checkout-idempotency.php
echo "[smoke] CoinPayments durable checkout and uncertain-result checks passed"

docker exec "$container_name" php /www/tests/smoke-user-device-read.php
echo "[smoke] Authenticated current-IP filtering and privacy checks passed"

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
