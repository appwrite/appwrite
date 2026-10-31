#!/usr/bin/env bash
# The `dns-peer` server the dns interop cases query from Rust: the PHP
# fixture server (fixtures/server.php on the Swoole adapter, serving the
# fixture zones) in a throwaway container of the dev image on the dev
# stack's network. PHP's client queries a server the Rust driver starts
# itself (`dns-peer-listen`), through host.docker.internal.
#
#   tests/compat/dns/services.sh up     # start it
#   bin/compat check dns
#   tests/compat/dns/services.sh down   # stop it
#
# Its endpoints match tests/compat/compat.json: UDP and TCP on
# localhost:17760, DNS over HTTP on 17761, PROXY-protocol TCP on 17762.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
state=/tmp/compat-fs/dns-peer

case "${1:-up}" in
up)
    mkdir -p "$state"
    rm -f "$state"/errors "$state"/workers "$state"/telemetry
    cat >"$state/config.json" <<JSON
{"state": "$state", "adapter": "swoole", "host": "0.0.0.0", "ports": {"udp": 5300, "tcp": 5300, "http": 5301, "proxy": 5302}, "resolver": {"kind": "fixture"}, "seed": 1}
JSON
    docker run -d --rm --network "$network" --name compat-dns-php \
        -p 17760:5300/udp -p 17760:5300/tcp -p 17761:5301 -p 17762:5302 \
        -v "$PWD/tests:/usr/src/code/tests" -v "$PWD/packages:/usr/src/code/packages" -v /tmp/compat-fs:/tmp/compat-fs \
        --entrypoint php "$image" /usr/src/code/tests/compat/dns/fixtures/server.php "$state/config.json" >/dev/null
    for _ in $(seq 1 60); do
        if [ -s "$state/workers" ] && (exec 3<>/dev/tcp/127.0.0.1/17760) 2>/dev/null; then
            exit 0
        fi
        sleep 1
    done
    echo "dns-peer did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-dns-php >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
