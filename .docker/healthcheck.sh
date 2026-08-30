#!/bin/sh
set -eu

is_enabled() {
    eval "value=\${$1:-false}"
    [ "$value" = "true" ]
}

has_process() {
    expected="$1"
    for command_line in /proc/[0-9]*/cmdline; do
        [ -r "$command_line" ] || continue
        if tr '\000' ' ' < "$command_line" 2>/dev/null | grep -Fq "$expected"; then
            return 0
        fi
    done
    echo "Required process is not running: $expected" >&2
    return 1
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
    has_process 'artisan horizon'
fi

if is_enabled ENABLE_WS_SERVER; then
    php -r '
        $socket = @fsockopen($argv[1], (int) $argv[2], $errorCode, $errorMessage, 2);
        if (!is_resource($socket)) {
            fwrite(STDERR, "WebSocket server is not accepting connections: {$errorCode} {$errorMessage}\n");
            exit(1);
        }
        fclose($socket);
    ' "${WS_HOST:-127.0.0.1}" "${WS_PORT:-8076}"
fi

if is_enabled ENABLE_CADDY; then
    has_process 'caddy run'
fi
