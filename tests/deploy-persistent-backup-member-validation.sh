#!/usr/bin/env bash
set -Eeuo pipefail

validate_members() {
  local archive_members="$1"
  local member

  for member in .env storage/theme plugins; do
    awk -v member="$member" '
      $0 == member || index($0, member "/") == 1 { found = 1 }
      END { exit(found ? 0 : 1) }
    ' <<<"$archive_members" || return 1
  done
}

validate_members $'.env\nstorage/theme/\nstorage/theme/Luck/file.js\nplugins/\nplugins/Telegram/config.json'

if validate_members $'.env\nstorage/theme/\nplugins-malicious/'; then
  echo 'Prefix collision incorrectly accepted plugins-malicious as plugins.' >&2
  exit 11
fi

if validate_members $'.env\nplugins/'; then
  echo 'Archive missing storage/theme was incorrectly accepted.' >&2
  exit 12
fi
