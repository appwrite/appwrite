#!/bin/sh
# Run the e2e suite against local workers plus a KEDA-driven kind cluster.
# Expects the compose services (redis, redis-cluster) to be up — see
# docker-compose.yml — and provisions kind + KEDA + the ScaledJob for KedaTest.
set -e
cd "$(dirname "$0")/.."

. tests/keda-lib.sh

php tests/Queue/servers/Swoole/worker.php & SWOOLE=$!
php tests/Queue/servers/SwooleRedisCluster/worker.php & CLUSTER=$!
php tests/Queue/servers/Workerman/worker.php start & WORKERMAN=$!
php tests/Queue/servers/Nats/worker.php & NATS=$!

cleanup() {
    kill -INT "$SWOOLE" "$CLUSTER" "$WORKERMAN" "$NATS" 2> /dev/null || true
    wait 2> /dev/null || true
    keda_down
}
trap cleanup EXIT INT TERM

# KEDA is required in CI: under `set -e` any provisioning failure aborts so KEDA
# regressions surface instead of KedaTest silently skipping. Locally it's
# best-effort — without Docker the rest of the suite still runs and KedaTest
# skips itself.
if [ -n "$CI" ] || { command -v docker > /dev/null 2>&1 && docker info > /dev/null 2>&1; }; then
    keda_up
else
    echo "e2e.sh: Docker unavailable, skipping KEDA setup — KedaTest will skip" >&2
fi

sleep 3

phpunit --testsuite e2e
