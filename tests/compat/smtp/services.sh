#!/usr/bin/env bash
# The Mailpit servers the smtp cases send to, as throwaway containers on the
# dev stack's network: the pair of packages/smtp/docker-compose.yml, once for
# each runtime (the runner sends a case's steps to PHP and Rust side by side,
# and each reads back the one message it sent).
#
#   tests/compat/smtp/services.sh up     # start them
#   bin/compat check smtp
#   tests/compat/smtp/services.sh down   # stop them
#
#   compat-smtp-mailpit[-rust]   STARTTLS, AUTH jane:secret, recipients @example.test only
#                                PHP: compat-smtp-mailpit:1025, API :8025
#                                Rust: localhost:16620, API localhost:16621
#   compat-smtp-implicit[-rust]  TLS from the first byte, AUTH jane:secret, two recipients a message
#                                PHP: compat-smtp-implicit:1025, API :8025
#                                Rust: localhost:16622, API localhost:16623
#
# All present the self-signed pair of packages/smtp/tests/fixtures/certs
# (CN and SAN localhost, SAN 127.0.0.1). Endpoints match tests/compat/compat.json.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="axllent/mailpit:v1.21"
certs="$PWD/packages/smtp/tests/fixtures/certs"
starttls=(-e MP_SMTP_TLS_CERT=/certs/cert.pem -e MP_SMTP_TLS_KEY=/certs/key.pem
    -e MP_SMTP_AUTH=jane:secret -e MP_SMTP_AUTH_ALLOW_INSECURE=1 -e 'MP_SMTP_ALLOWED_RECIPIENTS=@example\.test$')
implicit=(-e MP_SMTP_TLS_CERT=/certs/cert.pem -e MP_SMTP_TLS_KEY=/certs/key.pem
    -e MP_SMTP_REQUIRE_TLS=1 -e MP_SMTP_AUTH=jane:secret -e MP_SMTP_MAX_RECIPIENTS=2)
names=(compat-smtp-mailpit compat-smtp-implicit compat-smtp-mailpit-rust compat-smtp-implicit-rust)

run() {
    docker run -d --rm --network "$network" --name "$1" -v "$certs:/certs:ro" "${@:2}" "$image" >/dev/null
}

case "${1:-up}" in
up)
    run compat-smtp-mailpit "${starttls[@]}"
    run compat-smtp-implicit "${implicit[@]}"
    run compat-smtp-mailpit-rust -p 16620:1025 -p 16621:8025 "${starttls[@]}"
    run compat-smtp-implicit-rust -p 16622:1025 -p 16623:8025 "${implicit[@]}"
    for port in 16621 16623; do
        ready=""
        for _ in $(seq 1 30); do
            if curl -sf -o /dev/null "http://localhost:$port/readyz"; then
                ready=1
                break
            fi
            sleep 1
        done
        if [ -z "$ready" ]; then
            echo "mailpit on $port did not become ready" >&2
            exit 1
        fi
    done
    ;;
down)
    docker stop "${names[@]}" >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
