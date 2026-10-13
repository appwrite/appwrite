#!/usr/bin/env bash
# The `migration-api` fixture server the Appwrite source and destination
# cases call instead of a real Appwrite project (fixtures/api.php, a Swoole
# HTTP server, in a throwaway container of the dev image on the dev stack's
# network):
#
#   tests/compat/migration/services.sh up     # start it
#   bin/compat check migration
#   tests/compat/migration/services.sh down   # stop it
#
# Its endpoint matches tests/compat/compat.json: compat-migration-api:8080
# on the network, localhost:17820 from the host.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"

case "${1:-up}" in
up)
    if ! docker ps --format '{{.Names}}' | grep -qx compat-migration-api; then
        docker run -d --rm --network "$network" --name compat-migration-api -p 17820:8080 \
            -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
            /usr/src/code/tests/compat/migration/fixtures/api.php 8080 >/dev/null
    fi
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null "http://localhost:17820/__fixture/requests?project=ready"; then
            exit 0
        fi
        sleep 1
    done
    echo "migration-api did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-migration-api >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
