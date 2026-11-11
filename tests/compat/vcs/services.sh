#!/usr/bin/env bash
# The `vcs-http` and `vcs-https` fixture server the vcs cases call
# (fixtures/run.php: an emulator of every provider the library speaks to,
# with git repositories of its own, in a throwaway container of the dev
# image on the dev stack's network). No case ever reaches a real provider.
#
#   tests/compat/vcs/services.sh up     # start it
#   bin/compat check vcs
#   tests/compat/vcs/services.sh down   # stop it
#
# Its endpoints match tests/compat/compat.json: compat-vcs-http:8080 (HTTP)
# and :8443 (HTTPS, for Origin's Git endpoint) on the network,
# localhost:16950 and :16951 from the host. The HTTPS certificate is
# self-signed; the cases' git configuration skips verification.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
certs=/tmp/compat-fs/vcs-certs

case "${1:-up}" in
up)
    mkdir -p "$certs"
    if [ ! -f "$certs/server.pem" ]; then
        docker run --rm --user "$(id -u):$(id -g)" -v "$certs:/certs" --entrypoint openssl "$image" \
            req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=compat-vcs-http" \
            -addext "subjectAltName=DNS:compat-vcs-http,DNS:localhost,IP:127.0.0.1" \
            -keyout /certs/server.key -out /certs/server.pem >/dev/null 2>&1
        chmod 644 "$certs"/*
    fi
    docker run -d --rm --network "$network" --name compat-vcs-http -p 16950:8080 -p 16951:8443 \
        -v "$PWD/tests:/usr/src/code/tests" -v "$certs:$certs" --entrypoint php "$image" \
        /usr/src/code/tests/compat/vcs/fixtures/run.php 8080 8443 "$certs" >/dev/null
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null http://localhost:16950/_fixture/ready/caught; then
            exit 0
        fi
        sleep 1
    done
    echo "vcs-http did not become ready" >&2
    exit 1
    ;;
down)
    docker rm -f compat-vcs-http >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
