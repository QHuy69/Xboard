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

if [ -n "$current_container" ]; then
  previous_image="$(docker inspect --format '{{.Config.Image}}' "$current_container")"
  timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
  backup_name="database.sqlite.pre-${timestamp}"
  docker exec "$current_container" sqlite3 /www/.docker/.data/database.sqlite \
    "VACUUM INTO '/www/.docker/.data/${backup_name}'"
  echo "Database backup created: .docker/.data/${backup_name}"
fi

rollback() {
  if [ -z "$previous_image" ]; then
    return
  fi
  echo "Restoring previous image: $previous_image" >&2
  XBOARD_IMAGE="$previous_image" docker compose -f "$compose_file" up -d --wait --wait-timeout 120 xboard
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

new_container="$(XBOARD_IMAGE="$image" docker compose -f "$compose_file" ps -q xboard)"
actual_revision="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$new_container")"

if ! curl --fail --silent --show-error http://127.0.0.1:7001/api/v1/guest/comm/config >/dev/null \
  || ! curl --fail --silent --show-error http://127.0.0.1:7001/dashboard >/dev/null \
  || ! curl --fail --silent --show-error http://127.0.0.1:7001/Huy2006 >/dev/null; then
  echo "Post-deploy HTTP smoke test failed." >&2
  rollback
  exit 1
fi

echo "Deployment healthy: $image ($actual_revision)"
