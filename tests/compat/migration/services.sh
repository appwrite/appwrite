#!/usr/bin/env bash
# The fixture services the migration cases call instead of real Appwrite,
# Google, NHost and Supabase projects, in throwaway containers on the dev
# stack's network:
#
#   compat-migration-api           fixtures/api.php, a Swoole HTTP server standing in for an
#                                  Appwrite project's API, Google's APIs and the storage APIs
#   compat-migration-nhost-db      PostgreSQL with the PHP suite's NHost dump
#   compat-migration-supabase-db   PostgreSQL with the PHP suite's Supabase dump
#
#   tests/compat/migration/services.sh up     # start them
#   bin/compat check migration
#   tests/compat/migration/services.sh down   # stop them
#
# The endpoints match tests/compat/compat.json: migration-api on :8080 in the
# network and localhost:17820 from the host, the databases on :5432 and
# localhost:17821 (NHost) and :17822 (Supabase).
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
postgres="${COMPAT_MIGRATION_POSTGRES_IMAGE:-postgres:16}"
fixtures="$PWD/tests/compat/migration/fixtures"

running() {
    docker ps --format '{{.Names}}' | grep -qx "$1"
}

database() {
    local name="$1" port="$2" dump="$3"
    if ! running "$name"; then
        docker run -d --rm --network "$network" --name "$name" -p "$port:5432" \
            -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=postgres \
            -v "$fixtures/$dump:/dump:ro" -v "$fixtures/postgres-init.sh:/docker-entrypoint-initdb.d/init.sh:ro" \
            "$postgres" >/dev/null
    fi
}

case "${1:-up}" in
up)
    if ! running compat-migration-api; then
        docker run -d --rm --network "$network" --name compat-migration-api -p 17820:8080 \
            -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
            /usr/src/code/tests/compat/migration/fixtures/api.php 8080 >/dev/null
    fi
    database compat-migration-nhost-db 17821 nhost
    database compat-migration-supabase-db 17822 supabase
    for _ in $(seq 1 60); do
        if curl -sf -o /dev/null "http://localhost:17820/__fixture/requests?project=ready" \
            && docker exec compat-migration-nhost-db psql -U postgres -tAc 'SELECT 1 FROM auth.users LIMIT 1' >/dev/null 2>&1 \
            && docker exec compat-migration-supabase-db psql -U postgres -tAc 'SELECT 1 FROM storage.objects LIMIT 1' >/dev/null 2>&1; then
            exit 0
        fi
        sleep 1
    done
    echo "the migration fixture services did not become ready" >&2
    exit 1
    ;;
down)
    for name in compat-migration-api compat-migration-nhost-db compat-migration-supabase-db; do
        docker stop "$name" >/dev/null 2>&1 || true
    done
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
