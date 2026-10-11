#!/usr/bin/env bash
# The `client-http` and `client-https` fixture servers the client cases call
# (fixtures/server.php, a raw HTTP/1.1 server on Swoole coroutine sockets, in a
# throwaway container of the dev image on the dev stack's network):
#
#   tests/compat/client/services.sh up     # start it
#   bin/compat check client
#   tests/compat/client/services.sh down   # stop it
#
# Its endpoints match tests/compat/compat.json: compat-client-http:8080 (HTTP)
# and :8443 (HTTPS) on the network, localhost:16610 and :16611 from the host.
# The HTTPS listener's certificate is signed by a throwaway CA written to
# /tmp/compat-fs/client-certs/ca.pem, which both drivers read at that path.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
certs=/tmp/compat-fs/client-certs

openssl() {
    docker run --rm --user "$(id -u):$(id -g)" -v "$certs:/certs" --entrypoint openssl "$image" "$@" >/dev/null 2>&1
}

case "${1:-up}" in
up)
    mkdir -p "$certs"
    if [ ! -f "$certs/server.pem" ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=compat-client CA" \
            -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
            -keyout /certs/ca.key -out /certs/ca.pem
        openssl req -newkey rsa:2048 -nodes -subj "/CN=compat-client-http" \
            -keyout /certs/server.key -out /certs/server.csr
        printf 'subjectAltName=DNS:compat-client-http,DNS:localhost\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n' >"$certs/server.ext"
        openssl x509 -req -in /certs/server.csr -CA /certs/ca.pem -CAkey /certs/ca.key -CAcreateserial \
            -days 3650 -extfile /certs/server.ext -out /certs/server.pem
        chmod 644 "$certs"/*
    fi
    docker run -d --rm --network "$network" --name compat-client-http -p 16610:8080 -p 16611:8443 \
        -v "$PWD/tests:/usr/src/code/tests" -v "$certs:$certs" --entrypoint php "$image" \
        /usr/src/code/tests/compat/client/fixtures/server.php 8080 8443 "$certs" >/dev/null
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null http://localhost:16610/echo; then
            exit 0
        fi
        sleep 1
    done
    echo "client-http did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-client-http >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
