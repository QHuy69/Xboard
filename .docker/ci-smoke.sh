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
curl --fail --silent --show-error "http://127.0.0.1:${host_port}/Huy2006" >/dev/null
echo "[smoke] Public HTTP endpoints passed"

docker exec "$container_name" php /www/tests/smoke-order-idempotency.php
echo "[smoke] Order idempotency and disabled-surplus checks passed"

docker exec "$container_name" php /www/artisan schedule:list --no-ansi >/dev/null
echo "[smoke] Scheduler registration passed"

docker exec "$container_name" php /www/artisan migrate:status --no-ansi \
  | grep -q '2026_08_29_000003_enable_email_verification_and_set_admin_path.*Ran'
echo "[smoke] Required migration passed"

expected_revision="${GITHUB_SHA:-}"
if [ -n "$expected_revision" ]; then
  actual_revision="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$container_name")"
  test "$actual_revision" = "$expected_revision"
  echo "[smoke] Image revision label passed"
fi

echo "Container smoke test passed for $image"
