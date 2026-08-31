#!/usr/bin/env bash
set -Eeuo pipefail

image="${1:?usage: deploy-production.sh <immutable-image-tag-or-digest> [compose-file] [expected-git-revision]}"
compose_file="${2:-compose.production.yaml}"
expected_revision_prefix="${3:-}"

if [[ ! "$image" =~ @sha256:[0-9a-f]{64}$ && ! "$image" =~ :[0-9a-f]{7,40}$ ]]; then
  echo "Refusing mutable image reference: $image" >&2
  echo "Use a commit tag such as ghcr.io/qhuy69/xboard:572cd86 or an image digest." >&2
  exit 2
fi

if [ -z "$expected_revision_prefix" ] && [[ "$image" =~ :([0-9a-f]{7,40})$ ]]; then
  expected_revision_prefix="${BASH_REMATCH[1]}"
fi
if [ -n "$expected_revision_prefix" ] && ! [[ "$expected_revision_prefix" =~ ^[0-9a-f]{7,40}$ ]]; then
  echo "Invalid expected Git revision: $expected_revision_prefix" >&2
  exit 2
fi
if [[ "$image" =~ @sha256:[0-9a-f]{64}$ ]] && [ -z "$expected_revision_prefix" ]; then
  echo "Digest deployments require the intended Git revision as the third argument." >&2
  exit 2
fi
if [[ "$image" =~ @sha256:[0-9a-f]{64}$ ]] && ! [[ "$expected_revision_prefix" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Digest deployments require the complete 40-character Git revision." >&2
  exit 2
fi

test -f "$compose_file"
mkdir -p .docker
exec 9>".docker/deploy-production.lock"
if ! flock -n 9; then
  echo "Another Xboard deployment is already running; refusing concurrent mutation." >&2
  exit 75
fi
current_container="$(docker ps \
  --filter 'label=com.docker.compose.project=xboard' \
  --filter 'label=com.docker.compose.service=xboard' \
  --format '{{.ID}}' | head -n 1)"
existing_container="$(docker ps -a \
  --filter 'label=com.docker.compose.project=xboard' \
  --filter 'label=com.docker.compose.service=xboard' \
  --format '{{.ID}}' | head -n 1)"
if [ -n "$existing_container" ] && [ -z "$current_container" ]; then
  echo "An existing Xboard container is stopped; refusing a deployment without a live rollback baseline." >&2
  exit 1
fi
previous_image=""
previous_image_id=""
rollback_image=""
backup_name=""
timestamp=""
persistent_backup_path=""
failed_state_dir=""

create_persistent_backup() {
  local member
  umask 077
  test -f .env
  test -d storage/theme
  test -d plugins
  mkdir -p .docker/deploy-backups
  persistent_backup_path=".docker/deploy-backups/persistent-${timestamp}.tar"
  test ! -e "$persistent_backup_path"
  tar --numeric-owner --acls --xattrs -cpf "$persistent_backup_path" -- \
    .env storage/theme plugins "$compose_file"
  test "$(stat -c '%a' "$persistent_backup_path")" = 600
  for member in .env storage/theme plugins; do
    tar -tf "$persistent_backup_path" | grep -Eq "^${member}(/|$)" || {
      echo "Persistent backup is missing $member." >&2
      return 1
    }
  done
  echo "Persistent configuration backup created: $persistent_backup_path"
}

restore_persistent_backup() {
  test -n "$persistent_backup_path"
  test -f "$persistent_backup_path"
  failed_state_dir=".docker/deploy-failed-${timestamp}"
  test ! -e "$failed_state_dir"
  mkdir -m 700 "$failed_state_dir"

  # Preserve the failed release for diagnosis; no persistent data is deleted.
  test -f .env && mv .env "$failed_state_dir/env"
  test -d storage/theme && mv storage/theme "$failed_state_dir/theme"
  test -d plugins && mv plugins "$failed_state_dir/plugins"

  tar --numeric-owner --acls --xattrs -xpf "$persistent_backup_path" -- \
    .env storage/theme plugins
  test -f .env
  test -d storage/theme
  test -d plugins
  echo "Persistent configuration restored; failed state retained at $failed_state_dir" >&2
}

verify_coinpayments_checkout_schema() {
  local target_container="$1"
  local schema_state

  schema_state="$(docker exec "$target_container" sqlite3 /www/.docker/.data/database.sqlite "
    SELECT printf('%d:%d:%d',
      (
        SELECT COUNT(*)
        FROM pragma_table_info('v2_order_payment_checkout')
        WHERE \"notnull\" = 0
          AND (
            (name IN ('payment_uuid', 'provider_invoice_id', 'expected_amount') AND upper(type) LIKE 'VARCHAR%')
            OR (name = 'config_snapshot' AND upper(type) = 'TEXT')
            OR (name = 'provider_expires_at' AND upper(type) = 'INTEGER')
          )
      ),
      (
        SELECT
          CASE WHEN (
            SELECT group_concat(name, ',')
            FROM (
              SELECT name
              FROM pragma_index_info('order_payment_checkout_payment_state_idx')
              ORDER BY seqno
            )
          ) = 'payment_id,state'
            AND COALESCE((
              SELECT \"unique\"
              FROM pragma_index_list('v2_order_payment_checkout')
              WHERE name = 'order_payment_checkout_payment_state_idx'
            ), -1) = 0
          THEN 1 ELSE 0 END
          + CASE WHEN (
            SELECT group_concat(name, ',')
            FROM (
              SELECT name
              FROM pragma_index_info('order_payment_checkout_uuid_state_idx')
              ORDER BY seqno
            )
          ) = 'payment_uuid,state'
            AND COALESCE((
              SELECT \"unique\"
              FROM pragma_index_list('v2_order_payment_checkout')
              WHERE name = 'order_payment_checkout_uuid_state_idx'
            ), -1) = 0
          THEN 1 ELSE 0 END
          + CASE WHEN (
            SELECT group_concat(name, ',')
            FROM (
              SELECT name
              FROM pragma_index_info('order_payment_checkout_provider_invoice_unique')
              ORDER BY seqno
            )
          ) = 'provider,provider_invoice_id'
            AND COALESCE((
              SELECT \"unique\"
              FROM pragma_index_list('v2_order_payment_checkout')
              WHERE name = 'order_payment_checkout_provider_invoice_unique'
            ), -1) = 1
          THEN 1 ELSE 0 END
      ),
      (
        SELECT COUNT(*)
        FROM v2_order_payment_checkout
        WHERE provider = 'CoinPayments'
          AND state = 'ready'
          AND (
            trim(COALESCE(provider_invoice_id, '')) = ''
            OR provider_expires_at IS NULL
            OR provider_expires_at <= 0
          )
      )
    );
  ")" || return 1

  if [ "$schema_state" != '5:3:0' ]; then
    echo "CoinPayments migration 000005 schema gate failed (columns:indexes:invalid-ready=$schema_state)." >&2
    return 1
  fi
}

if [ -n "$current_container" ]; then
  timestamp="$(date -u '+%Y%m%dT%H%M%SZ')"
  create_persistent_backup
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
    echo "No previous release and database backup are available for rollback." >&2
    return
  fi

  if [ -z "$previous_image_id" ] || [ -z "$backup_name" ] || [ -z "$timestamp" ]; then
    echo "Rollback metadata is incomplete; refusing to start an old image against the migrated database." >&2
    return 1
  fi

  echo "Stopping the failed release before restoring the pre-deploy database backup." >&2
  XBOARD_IMAGE="$image" docker compose -f "$compose_file" stop -t 90 xboard

  restore_persistent_backup

  XBOARD_IMAGE="$previous_image" docker compose -f "$compose_file" run --rm --no-deps \
    --entrypoint /bin/sh \
    -e XBOARD_DB_BACKUP_NAME="$backup_name" \
    -e XBOARD_DB_RESTORE_TOKEN="$timestamp" \
    xboard -eu -c '
      data_dir=/www/.docker/.data
      backup_path="${data_dir}/${XBOARD_DB_BACKUP_NAME}"
      live_path="${data_dir}/database.sqlite"
      restore_path="${data_dir}/database.sqlite.restore-${XBOARD_DB_RESTORE_TOKEN}"

      test "$XBOARD_DB_BACKUP_NAME" = "database.sqlite.pre-${XBOARD_DB_RESTORE_TOKEN}"
      test -f "$backup_path"
      test -f "$live_path"
      test ! -e "$restore_path"
      cleanup_restore() {
        rm -f "$restore_path" "${restore_path}-wal" "${restore_path}-shm" "${restore_path}-journal"
      }
      trap cleanup_restore EXIT HUP INT TERM

      test "$(sqlite3 "$backup_path" "PRAGMA integrity_check;")" = ok

      cp "$backup_path" "$restore_path"
      test "$(sqlite3 "$restore_path" "PRAGMA integrity_check;")" = ok

      rm -f "${live_path}-wal" "${live_path}-shm" "${live_path}-journal"
      mv -f "$restore_path" "$live_path"
      sync

      test "$(sqlite3 "$live_path" "PRAGMA integrity_check;")" = ok
      cmp -s "$backup_path" "$live_path"
    '

  echo "Database restored from verified backup: .docker/.data/${backup_name}" >&2
  echo "Restoring previous image: $previous_image" >&2
  XBOARD_IMAGE="$previous_image" docker compose -f "$compose_file" up -d --wait --wait-timeout 120 xboard

  local rollback_container rollback_actual_image rollback_health rollback_integrity
  rollback_container="$(XBOARD_IMAGE="$previous_image" docker compose -f "$compose_file" ps -q xboard)"
  test -n "$rollback_container"

  rollback_actual_image="$(docker inspect --format '{{.Image}}' "$rollback_container")"
  if [ "$rollback_actual_image" != "$previous_image_id" ]; then
    echo "Rollback container did not use the captured previous image." >&2
    return 1
  fi

  rollback_health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$rollback_container")"
  if [ "$rollback_health" != healthy ]; then
    echo "Rollback container readiness failed (health=$rollback_health)." >&2
    return 1
  fi

  curl --fail --silent --show-error http://127.0.0.1:7001/api/v1/guest/comm/config >/dev/null
  rollback_integrity="$(docker exec "$rollback_container" sqlite3 /www/.docker/.data/database.sqlite 'PRAGMA integrity_check;')"
  if [ "$rollback_integrity" != ok ]; then
    echo "Rollback database integrity verification failed." >&2
    return 1
  fi

  echo "Rollback verified: pre-deploy database restored and previous image is ready." >&2
}

post_deploy_checks() {
  local container_id="$1"
  local expected_revision_prefix="$2"
  local started_at="$3"
  local health actual asset_revision_short migration_status integrity dashboard_html override_css luck_entry_js node_route_asset node_route_js dashboard_icon_marker dashboard_platform_glyph orders_route_asset traffic_route_asset invite_route_asset portable_route_asset orders_route_js traffic_route_js invite_route_js invite_icon_marker route_platform_glyph admin_html admin_js_path admin_css_path admin_asset_path admin_asset_file admin_asset_version admin_js_file admin_css_file admin_css_marker_found admin_favicon_svg_path admin_favicon_png_path admin_favicon_svg admin_favicon_url last_heartbeat telegram_plugin_state
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
  grep -q 'luck-overrides.css?v=27' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck CSS v27." >&2
    return 1
  }
  if grep -Fq 'document.body.appendChild(overlay)' <<<"$dashboard_html"; then
    echo "The deployed dashboard still manually reparents the Vue subscription overlay." >&2
    return 1
  fi
  grep -q 'BBbuoBq5-fresh.js?v=63' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck entry JS v63." >&2
    return 1
  }
  grep -q 'i18n-v18.js?v=61' <<<"$dashboard_html" || {
    echo "The deployed dashboard did not publish Luck i18n v61." >&2
    return 1
  }
  for dashboard_platform_marker in \
    "var PLATFORM_ORDER = ['windows', 'macos', 'linux', 'android', 'ios'];" \
    "target.searchParams.set('platform', platform);" \
    "target.hash = 'platform-' + platform;"; do
    grep -Fq "$dashboard_platform_marker" <<<"$dashboard_html" || {
      echo "The deployed Luck dashboard is missing platform marker: $dashboard_platform_marker" >&2
      return 1
    }
  done
  for resource_controller_marker in \
    "\$request->query('platform', '')" \
    "\$items->where('platform', \$selectedPlatform)" \
    "'empty_platform' =>"; do
    docker exec "$container_id" grep -aFq "$resource_controller_marker" \
      '/www/app/Http/Controllers/ResourcePortalController.php' || {
        echo "The deployed Resources controller is missing marker: $resource_controller_marker" >&2
        return 1
      }
  done
  for resource_view_marker in \
    'data-selected-platform="{{ $selectedPlatform }}"' \
    "target.scrollIntoView({ block: 'start' });" \
    "window.addEventListener('pageshow', reveal);"; do
    docker exec "$container_id" grep -aFq "$resource_view_marker" \
      '/www/resources/views/resources/portal.blade.php' || {
        echo "The deployed Resources portal is missing marker: $resource_view_marker" >&2
        return 1
      }
  done
  for resource_platform in windows macos linux android ios; do
    resource_html="$(curl --fail --silent --show-error \
      --header 'Host: resources.zaoguang-vpn.com' \
      "http://127.0.0.1:7001/?platform=${resource_platform}&lang=en-US")" || return 1
    grep -Fq '<html lang="en-US" dir="ltr">' <<<"$resource_html" || return 1
    grep -Fq "id=\"platform-${resource_platform}\" data-selected-platform=\"${resource_platform}\"" <<<"$resource_html" || {
      echo "The deployed Resources runtime did not select ${resource_platform}." >&2
      return 1
    }
  done
  resource_rtl_html="$(curl --fail --silent --show-error \
    --header 'Host: resources.zaoguang-vpn.com' \
    'http://127.0.0.1:7001/?platform=linux&lang=fa-IR')" || return 1
  grep -Fq '<html lang="fa-IR" dir="rtl">' <<<"$resource_rtl_html" || return 1
  resource_invalid_html="$(curl --fail --silent --show-error \
    --header 'Host: resources.zaoguang-vpn.com' \
    'http://127.0.0.1:7001/?platform=freebsd&lang=en-US')" || return 1
  grep -Fq 'id="apps" data-selected-platform=""' <<<"$resource_invalid_html" || {
    echo "The deployed Resources runtime accepted an unsupported platform." >&2
    return 1
  }
  for asset_url in \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-overrides.css?v=27' \
    'http://127.0.0.1:7001/theme/Luck/assets/BBbuoBq5-fresh.js?v=63' \
    'http://127.0.0.1:7001/theme/Luck/i18n-v18.js?v=61' \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-clash.svg' \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-flags.svg?v=1'; do
    curl --fail --silent --show-error --output /dev/null "$asset_url" || {
      echo "The deployed Luck asset is unavailable: $asset_url" >&2
      return 1
    }
  done

  override_css="$(curl --fail --silent --show-error \
    'http://127.0.0.1:7001/theme/Luck/assets/luck-overrides.css?v=27')" || return 1
  grep -Fq '.subscription-dialog-overlay' <<<"$override_css" || {
    echo "The deployed Luck CSS is missing the subscription viewport rule." >&2
    return 1
  }

  luck_entry_js="$(curl --fail --silent --show-error 'http://127.0.0.1:7001/theme/Luck/assets/BBbuoBq5-fresh.js?v=63')" || return 1
  if grep -aEq 'DM1yaN1X[^"?]*\.js\?v=3' <<<"$luck_entry_js"; then
    echo "The deployed Luck entry creates a duplicate cache-keyed Vue runtime." >&2
    return 1
  fi
  dashboard_route_asset="$(grep -oE '\./CO5Ntz5l[^"?]+\.js\?v=[0-9]+' <<<"$luck_entry_js" | sort -u | head -n 1)"
  case "$dashboard_route_asset" in
    ./CO5Ntz5l*.js?v=*) ;;
    *)
      echo "The deployed Luck entry has no cache-busted dashboard route: $dashboard_route_asset" >&2
      return 1
      ;;
  esac
  dashboard_route_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${dashboard_route_asset#./}")" || return 1
  shared_runtime_asset="$(grep -oE '\./BBbuoBq5[^"?]+\.js' <<<"$dashboard_route_js" | sort -u | head -n 1)"
  case "$shared_runtime_asset" in
    ./BBbuoBq5*-runtime-v3.js) ;;
    *)
      echo "The deployed Luck entry has no cache-busted shared runtime: $shared_runtime_asset" >&2
      return 1
      ;;
  esac
  shared_runtime_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${shared_runtime_asset#./}")" || return 1
  grep -aEq '\./C6e3mGRa[^"?]*-payment-v5\.js' <<<"$shared_runtime_js" || {
    echo "The deployed shared runtime does not select the Vue-owned dialog chunk." >&2
    return 1
  }
  if grep -aEq '\./C6e3mGRa[^"?]*-payment-v3\.js' <<<"$shared_runtime_js"; then
    echo "The deployed shared runtime can still revive the old payment-v3 dialog." >&2
    return 1
  fi
  subscription_dialog_asset="$(grep -oE '\./C6e3mGRa[^"?]+\.js' <<<"$luck_entry_js" | sort -u | head -n 1)"
  case "$subscription_dialog_asset" in
    ./C6e3mGRa*-payment-v5.js) ;;
    *)
      echo "The deployed Luck entry has no normalized subscription-dialog module: $subscription_dialog_asset" >&2
      return 1
      ;;
  esac
  subscription_dialog_js="$(curl --fail --silent --show-error \
    "http://127.0.0.1:7001/theme/Luck/assets/${subscription_dialog_asset#./}")" || {
      echo "The deployed subscription-dialog module is unavailable: $subscription_dialog_asset" >&2
      return 1
    }
  for subscription_dialog_marker in \
    'T as Teleport' \
    'name: "PortalledSubscriptionDialog"' \
    'inheritAttrs: false' \
    'createVNode(Teleport, { to: "body" }' \
    'window.__LUCK_OPEN_COINPAYMENTS_PAYMENT__' \
    'clipboard-read; clipboard-write; payment' \
    'const statusUrl = "/payment/status/"'; do
    grep -aFq "$subscription_dialog_marker" <<<"$subscription_dialog_js" || {
      echo "The deployed subscription dialog is missing Vue Teleport marker: $subscription_dialog_marker" >&2
      return 1
    }
  done

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
  docker exec "$container_id" grep -aFq 'name:"is_reseller"' "$admin_js_file" || {
    echo "The deployed admin bundle is missing the reseller role switch." >&2
    return 1
  }
  docker exec "$container_id" grep -aFq 'edit.form.is_reseller' "$admin_js_file" || return 1
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
  grep -q '2026_08_31_000005_add_coinpayments_checkout_snapshot.*Ran' <<<"$migration_status" || return 1
  grep -q '2026_08_31_120000_add_is_reseller_to_users.*Ran' <<<"$migration_status" || return 1
  grep -q '2026_09_01_000001_add_unique_telegram_id_to_users.*Ran' <<<"$migration_status" || return 1
  if grep -q 'Pending' <<<"$migration_status"; then
    echo "Post-deploy migration status still contains Pending entries." >&2
    printf '%s\n' "$migration_status" >&2
    return 1
  fi
  verify_coinpayments_checkout_schema "$container_id" || return 1
  telegram_plugin_state="$(docker exec "$container_id" sqlite3 /www/.docker/.data/database.sqlite \
    "SELECT version || ':' || is_enabled FROM v2_plugins WHERE code = 'telegram';")" || return 1
  case "$telegram_plugin_state" in
    '2.2.0:0'|'2.2.0:1') ;;
    *)
      echo "Deployed Telegram plugin state is $telegram_plugin_state; expected version 2.2.0 with a valid admin-controlled enabled state." >&2
      return 1
      ;;
  esac

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

pulled_revision="$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image")"
if ! [[ "$pulled_revision" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Pulled image has no valid immutable revision label: $pulled_revision" >&2
  exit 1
fi
case "$pulled_revision" in
  "$expected_revision_prefix"*) ;;
  *)
    echo "Image revision mismatch: expected $expected_revision_prefix, got $pulled_revision" >&2
    exit 1
    ;;
esac

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
