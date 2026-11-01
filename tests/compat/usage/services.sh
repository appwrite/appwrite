#!/usr/bin/env bash
# Services the usage cases need besides the dev stack: one ClickHouse server
# both runtimes use (each case works in its own database, named after its
# namespace), as a throwaway container on the dev stack's network. The
# Database adapter's cases use the database library's MariaDB
# (tests/compat/database/services.sh).
#
#   tests/compat/usage/services.sh up     # start it
#   bin/compat check usage
#   tests/compat/usage/services.sh down   # stop it
#
# The PHP driver reaches it as compat-usage-clickhouse:8123, the Rust driver
# and the runner through the host's port 16833 (tests/compat/compat.json).
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_CLICKHOUSE_IMAGE:-clickhouse/clickhouse-server:26.4.3-alpine}"
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

case "${1:-up}" in
up)
    if [ -z "$(docker ps -q --filter name=^compat-usage-clickhouse$)" ]; then
        docker run -d --rm --network "$network" --name compat-usage-clickhouse \
            -e CLICKHOUSE_USER=default -e CLICKHOUSE_PASSWORD=clickhouse \
            -e CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1 \
            -v "$dir/clickhouse.xml:/etc/clickhouse-server/config.d/compat.xml:ro" \
            -p 16833:8123 "$image" >/dev/null
    fi
    for _ in $(seq 1 90); do
        if docker exec compat-usage-clickhouse clickhouse-client --password clickhouse -q 'SELECT 1' >/dev/null 2>&1; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-usage-clickhouse >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
