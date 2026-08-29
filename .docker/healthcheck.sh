#!/bin/sh
set -eu

is_enabled() {
    eval "value=\${$1:-false}"
    [ "$value" = "true" ]
}

if is_enabled ENABLE_REDIS; then
    redis-cli -s /data/redis.sock ping 2>/dev/null | grep -q PONG
fi

if is_enabled ENABLE_WEB; then
    if is_enabled ENABLE_CADDY; then
        http_port="${CADDY_LISTEN_PORT:-7001}"
    else
        http_port="${OCTANE_PORT:-7001}"
    fi
    php -r '$body = @file_get_contents($argv[1]); exit($body === false ? 1 : 0);' \
        "http://127.0.0.1:${http_port}/api/v1/guest/comm/config"
fi

if is_enabled ENABLE_HORIZON; then
    pgrep -f 'artisan horizon' >/dev/null
fi

if is_enabled ENABLE_WS_SERVER; then
    pgrep -f 'artisan ws-server' >/dev/null
fi

if is_enabled ENABLE_CADDY; then
    pgrep -f 'caddy run' >/dev/null
fi
