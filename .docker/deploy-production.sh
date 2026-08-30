#!/usr/bin/env bash
set -Eeuo pipefail

image="${1:?usage: deploy-production.sh <immutable-image-tag-or-digest>}"
compose_file="${2:-compose.production.yaml}"

if [[ ! "$image" =~ @sha256:[0-9a-f]{64}$ && ! "$image" =~ :[0-9a-f]{7,40}$ ]]; then
  echo "Refusing mutable image reference: $image" >&2
  echo "Use a commit tag such as ghcr.io/qhuy69/xboard:572cd86 or an image digest." >&2
  exit 2
fi

test -f "$compose_file"
current_container="$(docker ps \
  --filter 'label=com.docker.compose.project=xboard' \
  --filter 'label=com.docker.compose.service=xboard' \
  --format '{{.ID}}' | head -n 1)"
previous_image=""
rollback_image=""

if [ -n "$current_container" ]; then
  timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
  previous_image_id="$(docker inspect --format '{{.Image}}' "$current_container")"
  rollback_image="xboard-local-rollback:${timestamp}"
  docker image tag "$previous_image_id" "$rollback_image"
  previous_image="$rollback_image"

  backup_name="database.sqlite.pre-${timestamp}"
  docker exec "$current_container" sqlite3 /www/.docker/.data/database.sqlite \
    "VACUUM INTO '/www/.docker/.data/${backup_name}'"
  docker exec "$current_container" sqlite3 "/www/.docker/.data/${backup_name}" \
    'PRAGMA integrity_check;' | grep -qx 'ok'
  echo "Database backup created: .docker/.data/${backup_name}"
  echo "Rollback image retained: $rollback_image"
fi

rollback() {
  if [ -z "$previous_image" ]; then
    return
  fi
  echo "Restoring previous image: $previous_image" >&2
  XBOARD_IMAGE="$previous_image" docker compose -f "$compose_file" up -d --wait --wait-timeout 120 xboard
}

post_deploy_checks() {
  local container_id="$1"
  local expected_revision_prefix="$2"
  local started_at="$3"
  local health actual migration_status integrity dashboard_html ip_status last_heartbeat

  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_id")" || return 1
  if [ "$health" != "healthy" ]; then
    echo "Post-deploy container health is $health, expected healthy." >&2
    return 1
  fi

  actual="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$container_id")" || return 1
  if [ -n "$expected_revision_prefix" ]; then
    case "$actual" in
      "$expected_revision_prefix"*) ;;
      *)
        echo "Running container revision mismatch: expected $expected_revision_prefix, got $actual" >&2
        return 1
        ;;
    esac
  fi

  curl --fail --silent --show-error http://127.0.0.1:7001/api/v1/guest/comm/config >/dev/null || return 1
  dashboard_html="$(curl --fail --silent --show-error http://127.0.0.1:7001/dashboard)" || return 1
  curl --fail --silent --show-error http://127.0.0.1:7001/Huy2006 >/dev/null || return 1
  grep -q 'luck-overrides.css?v=18' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck CSS v18." >&2
    return 1
  }
  grep -q 'BBbuoBq5-fresh.js?v=59' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck entry JS v59." >&2
    return 1
  }
  grep -q 'i18n-v18.js?v=60' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck i18n v60." >&2
    return 1
  }
  for asset_url in \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-overrides.css?v=18' \
    'http://127.0.0.1:7001/theme/Luck/assets/BBbuoBq5-fresh.js?v=59' \
    'http://127.0.0.1:7001/theme/Luck/i18n-v18.js?v=60'; do
    curl --fail --silent --show-error --output /dev/null "$asset_url" || {
      echo "The deployed Luck asset is unavailable: $asset_url" >&2
      return 1
    }
  done

  ip_status="$(curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1:7001/api/v1/user/devices/current)" || return 1
  case "$ip_status" in
    401|403) ;;
    *)
      echo "Authenticated current-IP route returned unexpected HTTP $ip_status." >&2
      return 1
      ;;
  esac

  migration_status="$(docker exec "$container_id" php /www/artisan migrate:status --no-ansi)" || return 1
  grep -q '2026_08_29_000003_enable_email_verification_and_set_admin_path.*Ran' <<<"$migration_status" || return 1
  grep -q '2026_08_30_000004_create_order_payment_checkouts.*Ran' <<<"$migration_status" || return 1
  if grep -q 'Pending' <<<"$migration_status"; then
    echo "Post-deploy migration status still contains Pending entries." >&2
    printf '%s\n' "$migration_status" >&2
    return 1
  fi

  integrity="$(docker exec "$container_id" sqlite3 /www/.docker/.data/database.sqlite 'PRAGMA integrity_check;')" || return 1
  if [ "$integrity" != "ok" ]; then
    echo "Post-deploy SQLite integrity check failed: $integrity" >&2
    return 1
  fi

  docker exec "$container_id" php /www/artisan schedule:list --no-ansi >/dev/null || return 1
  for _ in $(seq 1 30); do
    last_heartbeat="$(docker exec "$container_id" php -r '
      require "/www/vendor/autoload.php";
      $app = require "/www/bootstrap/app.php";
      $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      echo (int) \Illuminate\Support\Facades\Cache::get(\App\Utils\CacheKey::get("SCHEDULE_LAST_CHECK_AT", null), 0);
    ' 2>/dev/null || true)"
    if [[ "$last_heartbeat" =~ ^[0-9]+$ ]] && [ "$last_heartbeat" -gt "$started_at" ]; then
      return 0
    fi
    sleep 3
  done

  echo "The dedicated scheduler did not write a post-deploy heartbeat." >&2
  return 1
}

XBOARD_IMAGE="$image" docker compose -f "$compose_file" config --quiet
XBOARD_IMAGE="$image" docker compose -f "$compose_file" pull xboard

if [[ "$image" =~ :([0-9a-f]{7,40})$ ]]; then
  expected_tag="${BASH_REMATCH[1]}"
  pulled_revision="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")"
  case "$pulled_revision" in
    "$expected_tag"*) ;;
    *)
      echo "Image revision mismatch: expected $expected_tag, got $pulled_revision" >&2
      exit 1
      ;;
  esac
fi

if ! XBOARD_IMAGE="$image" docker compose -f "$compose_file" up -d --wait --wait-timeout 120 xboard; then
  echo "New container did not become healthy." >&2
  rollback
  exit 1
fi

if ! new_container="$(XBOARD_IMAGE="$image" docker compose -f "$compose_file" ps -q xboard)" \
  || [ -z "$new_container" ]; then
  echo "Could not resolve the newly started container." >&2
  rollback
  exit 1
fi

if ! container_started_at="$(docker inspect --format '{{.State.StartedAt}}' "$new_container")" \
  || ! new_container_started_epoch="$(date -d "$container_started_at" '+%s')"; then
  echo "Could not resolve the new container start time." >&2
  rollback
  exit 1
fi

expected_revision_prefix=""
if [[ "$image" =~ :([0-9a-f]{7,40})$ ]]; then
  expected_revision_prefix="${BASH_REMATCH[1]}"
fi

if ! post_deploy_checks "$new_container" "$expected_revision_prefix" "$new_container_started_epoch"; then
  echo "Post-deploy release gate failed." >&2
  rollback
  exit 1
fi

if ! actual_revision="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$new_container")"; then
  echo "Could not read the verified container revision." >&2
  rollback
  exit 1
fi

echo "Deployment healthy: $image ($actual_revision)"
