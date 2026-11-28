#!/usr/bin/env bash
# The `cdn-http` fixture server the cdn cases call through the providers'
# default HTTP client (fixtures/server.php, PHP's built-in server in a
# throwaway container on the dev stack's network), standing in for the
# Cloudflare and Fastly APIs so no CDN is ever reached:
#
#   tests/compat/cdn/services.sh up     # start it
#   bin/compat check cdn
#   tests/compat/cdn/services.sh down   # stop it
#
# Its endpoint matches tests/compat/compat.json: compat-cdn-http:8080 on the
# network, localhost:16710 from the host.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-cdn-http -p 16710:8080 \
        -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
        -S 0.0.0.0:8080 /usr/src/code/tests/compat/cdn/fixtures/server.php >/dev/null
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null http://localhost:16710/_take/ready; then
            exit 0
        fi
        sleep 1
    done
    echo "cdn-http did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-cdn-http >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
