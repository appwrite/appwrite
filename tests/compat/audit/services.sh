#!/usr/bin/env bash
# Services the audit cases need besides the database library's MariaDB
# (tests/compat/database/services.sh): a ClickHouse server of their own, as a
# throwaway container on the dev stack's network, published on the host for
# the Rust driver and the runner. Each case works in a ClickHouse database
# named after its namespace (the `clickhouse@audit-clickhouse` snapshot), so
# the dev stack's own ClickHouse is never touched.
#
#   tests/compat/audit/services.sh up     # start it
#   bin/compat check audit
#   tests/compat/audit/services.sh down   # stop it
#
# Endpoints match tests/compat/compat.json (`audit-clickhouse`). The
# credentials are test values.
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_CLICKHOUSE_IMAGE:-clickhouse/clickhouse-server:26.4.3-alpine}"

case "${1:-up}" in
up)
    if ! docker container inspect compat-audit-clickhouse >/dev/null 2>&1; then
        docker run -d --rm --network "$network" --name compat-audit-clickhouse \
            -e CLICKHOUSE_DB=default -e CLICKHOUSE_USER=default -e CLICKHOUSE_PASSWORD=clickhouse \
            -e CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1 \
            -p 16823:8123 "$image" >/dev/null
    fi
    for _ in $(seq 1 90); do
        if docker exec compat-audit-clickhouse clickhouse-client --password clickhouse --query 'SELECT 1' >/dev/null 2>&1; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-audit-clickhouse >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
