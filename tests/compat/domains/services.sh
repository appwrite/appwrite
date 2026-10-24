#!/usr/bin/env bash
# The `domains-http` fixture server the domains cases call (fixtures/server.php,
# PHP's built-in server in a throwaway container on the dev stack's network):
#
#   tests/compat/domains/services.sh up     # start it
#   bin/compat check domains
#   tests/compat/domains/services.sh down   # stop it
#
# Its endpoint matches tests/compat/compat.json: compat-domains-http:8080 on
# the network, localhost:9590 from the host.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-domains-http -p 9590:8080 \
        -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
        -S 0.0.0.0:8080 /usr/src/code/tests/compat/domains/fixtures/server.php >/dev/null
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null http://localhost:9590/json; then
            exit 0
        fi
        sleep 1
    done
    echo "domains-http did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-domains-http >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
