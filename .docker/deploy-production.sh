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
  local health actual asset_revision_short migration_status integrity dashboard_html luck_entry_js node_route_asset node_route_js dashboard_icon_marker dashboard_platform_glyph orders_route_asset traffic_route_asset invite_route_asset portable_route_asset orders_route_js traffic_route_js invite_route_js invite_icon_marker route_platform_glyph admin_html admin_js_path admin_css_path admin_asset_path admin_asset_file admin_asset_version admin_js_file admin_css_file admin_css_marker_found admin_favicon_svg_path admin_favicon_png_path admin_favicon_svg admin_favicon_url last_heartbeat
  local -a admin_js_paths admin_css_paths admin_locale_paths

  health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$container_id")" || return 1
  if [ "$health" != "healthy" ]; then
    echo "Post-deploy container health is $health, expected healthy." >&2
    return 1
  fi

  actual="$(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$container_id")" || return 1
  if ! [[ "$actual" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Running container has no valid immutable revision label: $actual" >&2
    return 1
  fi
  asset_revision_short="${actual:0:7}"
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
  admin_html="$(curl --fail --silent --show-error http://127.0.0.1:7001/Huy2006)" || return 1
  grep -q 'luck-overrides.css?v=22' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck CSS v22." >&2
    return 1
  }
  grep -q 'BBbuoBq5-fresh.js?v=61' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck entry JS v61." >&2
    return 1
  }
  grep -q 'i18n-v18.js?v=61' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck i18n v61." >&2
    return 1
  }
  for asset_url in \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-overrides.css?v=22' \
    'http://127.0.0.1:7001/theme/Luck/assets/BBbuoBq5-fresh.js?v=61' \
    'http://127.0.0.1:7001/theme/Luck/i18n-v18.js?v=61' \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-clash.svg' \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-flags.svg?v=1'; do
    curl --fail --silent --show-error --output /dev/null "$asset_url" || {
      echo "The deployed Luck asset is unavailable: $asset_url" >&2
      return 1
    }
  done

  luck_entry_js="$(curl --fail --silent --show-error 'http://127.0.0.1:7001/theme/Luck/assets/BBbuoBq5-fresh.js?v=61')" || return 1
  node_route_asset="$(grep -oE '\./oPGsis9D[^"?]+\.js' <<<"$luck_entry_js" | sort -u | head -n 1)"
  case "$node_route_asset" in
    ./oPGsis9D*-access-v2.js) ;;
    *)
      echo "The deployed Luck entry has no normalized node-route module: $node_route_asset" >&2
      return 1
      ;;
  esac
  node_route_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${node_route_asset#./}")" || {
      echo "The deployed lazy node-route module is unavailable: $node_route_asset" >&2
      return 1
    }
  if grep -aFq '/flags/' <<<"$node_route_js"; then
    echo "The deployed Luck node route still requests the missing /flags directory." >&2
    return 1
  fi
  for node_flag_marker in \
    'luck-flags.svg?v=1#${flagAssetCode}' \
    'luck-flags.svg?v=1#${mobileFlagAssetCode}' \
    'const mobileFlagCode =' \
    'toDisplayString(mobileDisplayName)' \
    '"scrollbar-props": { trigger: "none" }'; do
    grep -aFq "$node_flag_marker" <<<"$node_route_js" || {
      echo "The deployed Luck node route is missing portable flag marker: $node_flag_marker" >&2
      return 1
    }
  done
  node_flag_host_count="$( { grep -aoF 'class: "luck-node-flag"' <<<"$node_route_js" || true; } | wc -l | tr -d '[:space:]')"
  if [ "$node_flag_host_count" -lt 2 ]; then
    echo "The deployed Luck node route patched only $node_flag_host_count of 2 flag renderers." >&2
    return 1
  fi

  for dashboard_icon_marker in \
    'data-luck-icon="language"'; do
    grep -aFq "$dashboard_icon_marker" <<<"$dashboard_html" || {
      echo "The deployed Luck dashboard is missing portable inline icon marker: $dashboard_icon_marker" >&2
      return 1
    }
  done
  for dashboard_platform_glyph in '🌐'; do
    if grep -aFq "$dashboard_platform_glyph" <<<"$dashboard_html"; then
      echo "The deployed Luck dashboard still contains platform-dependent glyph: $dashboard_platform_glyph" >&2
      return 1
    fi
  done

  orders_route_asset="$( { grep -aoE '\./lsrL0SOU[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
  traffic_route_asset="$( { grep -aoE '\./BR9H_Zte[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
  invite_route_asset="$( { grep -aoE '\./DSCv3-VU[^"?]+\.js\?v=2' <<<"$luck_entry_js" || true; } | sort -u | head -n 1)"
  for portable_route_asset in "$orders_route_asset" "$traffic_route_asset" "$invite_route_asset"; do
    if [ -z "$portable_route_asset" ]; then
      echo "The deployed Luck entry is missing a versioned portable-icon lazy route." >&2
      return 1
    fi
  done
  orders_route_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${orders_route_asset#./}")" || return 1
  traffic_route_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${traffic_route_asset#./}")" || return 1
  invite_route_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${invite_route_asset#./}")" || return 1
  grep -aFq '"data-luck-icon": "orders-empty"' <<<"$orders_route_js" || {
    echo "The deployed orders route is missing its portable empty-state icon." >&2
    return 1
  }
  grep -aFq '"data-luck-icon": "traffic-empty"' <<<"$traffic_route_js" || {
    echo "The deployed traffic route is missing its portable empty-state icon." >&2
    return 1
  }
  for invite_icon_marker in warning balance record hint; do
    grep -aFq "\"data-luck-icon\": \"${invite_icon_marker}\"" <<<"$invite_route_js" || {
      echo "The deployed invite route is missing portable icon: $invite_icon_marker" >&2
      return 1
    }
  done
  for route_platform_glyph in '📋' '📊' '⚠️' '💰' '📝' '💡'; do
    if grep -aFq "$route_platform_glyph" <<<"${orders_route_js}${traffic_route_js}${invite_route_js}"; then
      echo "The deployed Luck lazy routes still contain platform-dependent glyph: $route_platform_glyph" >&2
      return 1
    fi
  done

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
    echo "The deployed admin shell emitted an unexpected asset set: ${#admin_js_paths[@]} entry JS, ${#admin_css_paths[@]} CSS, ${#admin_locale_paths[@]} locales, SVG favicon=$admin_favicon_svg_path, PNG favicon=$admin_favicon_png_path." >&2
    return 1
  fi
  admin_asset_version="$(docker exec "$container_id" php -r '
    require "/www/vendor/autoload.php";
    $app = require "/www/bootstrap/app.php";
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo rawurlencode((string) config("app.version", ""));
  ')" || return 1
  if [ -z "$admin_asset_version" ]; then
    echo "The deployed admin asset URL does not match config app.version: $admin_asset_version" >&2
    return 1
  fi
  for admin_asset_path in "${admin_js_paths[@]}" "${admin_css_paths[@]}" "${admin_locale_paths[@]}" "$admin_favicon_svg_path" "$admin_favicon_png_path"; do
    if [ "${admin_asset_path##*\?v=}" != "$admin_asset_version" ]; then
      echo "The deployed admin asset URL does not match config app.version: $admin_asset_path" >&2
      return 1
    fi
    admin_asset_file="/www/public${admin_asset_path%%\?*}"
    docker exec "$container_id" test -f "$admin_asset_file" || {
      echo "The deployed admin asset is missing: $admin_asset_file" >&2
      return 1
    }
  done
  case "$admin_asset_version" in
    *-"$asset_revision_short") ;;
    *)
      echo "Admin asset version $admin_asset_version does not match immutable image revision $asset_revision_short." >&2
      return 1
      ;;
  esac
  admin_js_path="${admin_js_paths[0]}"
  admin_js_file="/www/public${admin_js_path%%\?*}"
  docker exec "$container_id" grep -aFq 'role:"img","aria-label":"Việt Nam"' "$admin_js_file" || {
    echo "The deployed admin bundle is missing the portable Vietnamese SVG flag." >&2
    return 1
  }
  docker exec "$container_id" grep -aFq 'viewBox:"0 0 30 20"' "$admin_js_file" || return 1
  admin_css_marker_found=false
  for admin_css_path in "${admin_css_paths[@]}"; do
    admin_css_file="/www/public${admin_css_path%%\?*}"
    if docker exec "$container_id" grep -aFq 'xboard-admin-icon-visibility' "$admin_css_file"; then
      admin_css_marker_found=true
    fi
  done
  if [ "$admin_css_marker_found" != true ]; then
    echo "The deployed admin stylesheet is missing the icon shrink guard." >&2
    return 1
  fi
  admin_favicon_svg="$(curl --fail --silent --show-error "http://127.0.0.1:7001${admin_favicon_svg_path}")" || return 1
  grep -aFq 'aria-label="ZaoGuang admin"' <<<"$admin_favicon_svg" || {
    echo "The deployed admin SVG favicon is not the packaged brand asset." >&2
    return 1
  }
  grep -aFq 'stroke="#fff"' <<<"$admin_favicon_svg" || return 1
  for admin_favicon_url in "$admin_favicon_png_path" '/images/favicon.svg'; do
    curl --fail --silent --show-error --output /dev/null "http://127.0.0.1:7001${admin_favicon_url}" || {
      echo "The deployed admin favicon is unavailable: $admin_favicon_url" >&2
      return 1
    }
  done
  docker exec "$container_id" php -r '
    $png = file_get_contents("/www/public/images/favicon.png");
    exit(is_string($png) && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n" ? 0 : 1);
  ' || {
    echo "The deployed admin PNG favicon has an invalid signature." >&2
    return 1
  }

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
