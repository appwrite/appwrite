#!/usr/bin/env bash
# The NATS servers the nats cases need, as throwaway containers on the dev
# stack's network:
#
#   tests/compat/nats/services.sh up     # start them
#   bin/compat check nats
#   tests/compat/nats/services.sh down   # stop them
#
# Endpoints match tests/compat/compat.json: a NATS server with JetStream on
# 16901, one requiring mutual TLS on 16902 (TLS first, with the PHP tests'
# fixture certificates, valid for localhost), and one serving NATS over
# WebSockets on 16903 (packages/nats/tests/fixtures and docker-compose.yml).
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
fixtures="$PWD/packages/nats/tests/fixtures"

# Starts a container unless one of that name is already running.
start() {
    local name="$1"
    shift
    if [ -z "$(docker ps -q -f "name=^${name}$")" ]; then
        docker run -d --rm --network "$network" --name "$name" "$@" >/dev/null
    fi
}

ready() {
    docker exec "$1" wget --spider -q http://127.0.0.1:8222/healthz 2>/dev/null
}

case "${1:-up}" in
up)
    start compat-nats -p 16901:4222 nats:2.11-alpine -js -m 8222
    start compat-nats-tls -p 16902:4222 -v "$fixtures/certs:/certs:ro" -v "$fixtures/nats-tls.conf:/nats.conf:ro" \
        nats:2.11-alpine -c /nats.conf
    start compat-nats-ws -p 16903:8080 -v "$fixtures/nats-ws.conf:/nats.conf:ro" nats:2.11-alpine -c /nats.conf
    for _ in $(seq 1 60); do
        if ready compat-nats && ready compat-nats-tls && ready compat-nats-ws; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-nats compat-nats-tls compat-nats-ws >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
