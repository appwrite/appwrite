#!/bin/sh
# Run the end-to-end suite against local Swoole and Workerman fixture servers.
set -eu
cd "$(dirname "$0")/.."

php tests/Fixtures/Swoole/server.php &
SWOOLE_PID=$!
php tests/Fixtures/Workerman/server.php start &
WORKERMAN_PID=$!

cleanup() {
    kill -TERM "$SWOOLE_PID" "$WORKERMAN_PID" 2>/dev/null || true
    wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

wait_for_port() {
    port=$1
    attempts=0

    # The dollar signs below belong to PHP, not the shell.
    # shellcheck disable=SC2016
    until php -r '$socket = @fsockopen("127.0.0.1", (int) $argv[1]); if ($socket === false) { exit(1); } fclose($socket);' "$port"; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 50 ]; then
            echo "WebSocket fixture did not start on port $port" >&2
            return 1
        fi
        sleep 0.1
    done
}

wait_for_port 18081
wait_for_port 18082

phpunit --testsuite e2e
