#!/usr/bin/env bash
# The PHP fixture broker the mqtt interop cases drive from Rust (and that PHP
# clients reach as the other side's broker): tests/compat/mqtt/server.php in
# fixture mode, packages/mqtt's E2E fixture on the Swoole adapter, in a
# throwaway container of the dev image on the dev stack's network.
#
#   tests/compat/mqtt/services.sh up     # start it
#   bin/compat check mqtt
#   tests/compat/mqtt/services.sh down   # stop it
#
# Its endpoints match tests/compat/compat.json: compat-mqtt-broker:1883 (TCP),
# :8083 (WebSocket) and :8883 (TLS) on the network; 127.0.0.1:16700, :16701
# and :16702 from the host. The TLS listeners (this broker's, and those the
# e2e cases start in each driver) use the self-signed certificate written to
# /tmp/compat-fs/mqtt-certs/server.pem (key server.key), which both drivers
# read at that path. A Rust broker a case starts listens on the host, where
# PHP reaches it at host.docker.internal.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
certs=/tmp/compat-fs/mqtt-certs

case "${1:-up}" in
up)
    mkdir -p "$certs"
    if [ ! -f "$certs/server.pem" ]; then
        docker run --rm --user "$(id -u):$(id -g)" -v "$certs:/certs" --entrypoint openssl "$image" \
            req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=compat-mqtt-broker" \
            -addext "subjectAltName=DNS:compat-mqtt-broker,DNS:localhost,DNS:host.docker.internal,IP:127.0.0.1" \
            -keyout /certs/server.key -out /certs/server.pem >/dev/null 2>&1
        chmod 644 "$certs"/*
    fi
    docker run -d --rm --network "$network" --name compat-mqtt-broker \
        -p 16700:1883 -p 16701:8083 -p 16702:8883 \
        -v "$PWD/tests:/usr/src/code/tests" -v "$PWD/packages:/usr/src/code/packages" -v "$certs:$certs" \
        -w /usr/src/code --entrypoint php "$image" \
        tests/compat/mqtt/server.php fixture 0.0.0.0 1883 8083 8883 "$certs/server.pem" "$certs/server.key" >/dev/null
    for _ in $(seq 1 30); do
        if (exec 3<>/dev/tcp/127.0.0.1/16700) 2>/dev/null && (exec 3<>/dev/tcp/127.0.0.1/16702) 2>/dev/null; then
            exit 0
        fi
        sleep 1
    done
    echo "compat-mqtt-broker did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-mqtt-broker >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
