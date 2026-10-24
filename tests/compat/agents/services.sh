#!/usr/bin/env bash
# The `agents-http` fixture server the agents cases send their provider
# requests to instead of OpenAI, Anthropic, Gemini, OpenRouter and the others
# (fixtures/server.php, a raw HTTP/1.1 server on Swoole coroutine sockets that
# records each request and answers with responses the cases queue, streamed in
# chunks when asked), in a throwaway container of the dev image on the dev
# stack's network. The OpenRouter model sync task's cases download their
# catalogs from it too.
#
#   tests/compat/agents/services.sh up     # start it
#   bin/compat check agents
#   tests/compat/agents/services.sh down   # stop it
#
# Its endpoint matches tests/compat/compat.json: compat-agents-http:8080 on the
# network, 127.0.0.1:16640 from the host.
set -euo pipefail
cd "$(dirname "$0")/../../.."
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_BASE_IMAGE:-${COMPAT_IMAGE:-appwrite-dev}}"

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-agents-http -p 16640:8080 \
        -v "$PWD/tests:/usr/src/code/tests" --entrypoint php "$image" \
        /usr/src/code/tests/compat/agents/fixtures/server.php 8080 >/dev/null
    for _ in $(seq 1 60); do
        if curl -sf -o /dev/null http://127.0.0.1:16640/health; then
            exit 0
        fi
        sleep 1
    done
    echo "agents-http did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-agents-http >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
