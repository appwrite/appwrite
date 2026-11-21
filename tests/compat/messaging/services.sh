#!/usr/bin/env bash
# The servers the messaging cases send to, as throwaway containers on the dev
# stack's network, so no provider is ever reached:
#
#   tests/compat/messaging/services.sh up     # start them
#   bin/compat check messaging
#   tests/compat/messaging/services.sh down   # stop them
#
#   compat-messaging-http      fixtures/server.php: every provider API (Twilio, Mailgun,
#                              SES, FCM, APNs, Meta, ...), recording each request and
#                              answering with the replies a case queued
#                              PHP: compat-messaging-http:8080, Rust: localhost:16730
#   compat-messaging-mailpit   an SMTP sink (no TLS, no authentication) for the SMTP
#                              adapter's end-to-end cases, read back through its API
#                              PHP: compat-messaging-mailpit:1025, API :8025
#                              Rust: localhost:16731, API localhost:16732
#
# Endpoints match tests/compat/compat.json. The fixture verifies JWTs with the
# throwaway keys in fixtures/keys (generated for these tests, never used anywhere else).
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"
names=(compat-messaging-http compat-messaging-mailpit)

wait_for() {
    for _ in $(seq 1 30); do
        if curl -sf -o /dev/null "$1"; then
            return 0
        fi
        sleep 1
    done
    echo "$2 did not become ready" >&2
    exit 1
}

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-messaging-http -p 16730:8080 \
        -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
        /usr/src/code/tests/compat/messaging/fixtures/server.php 8080 /usr/src/code/tests/compat/messaging/fixtures/keys >/dev/null
    docker run -d --rm --network "$network" --name compat-messaging-mailpit -p 16731:1025 -p 16732:8025 \
        -e MP_SMTP_AUTH_ACCEPT_ANY=1 -e MP_SMTP_AUTH_ALLOW_INSECURE=1 axllent/mailpit:v1.21 >/dev/null
    wait_for http://localhost:16730/_ready messaging-http
    wait_for http://localhost:16732/readyz messaging-mailpit
    ;;
down)
    docker stop "${names[@]}" >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
