#!/usr/bin/env bash
# Services the queue cases need besides the dev stack's Redis, as throwaway
# containers on the dev stack's network:
#
#   tests/compat/queue/services.sh up     # start them
#   bin/compat check queue
#   tests/compat/queue/services.sh down   # stop them
#
# Endpoints match tests/compat/compat.json: a Redis with a password and ACL
# users on 16480, a NATS server with JetStream on 14422, and one Redis Cluster
# per runtime (the PHP driver's announces its addresses on the network, the
# Rust driver's 127.0.0.1:17200-17202), since a cluster advertises the
# addresses its clients must dial. Redis itself is the dev stack's.
set -euo pipefail
cd "$(dirname "$0")"
network="${COMPAT_NETWORK:-appwrite}"

# Starts a container unless one of that name is already running.
start() {
    local name="$1"
    shift
    if [ -z "$(docker ps -q -f "name=^${name}$")" ]; then
        docker run -d --rm --network "$network" --name "$name" "$@" >/dev/null
    fi
}

case "${1:-up}" in
up)
    start compat-queue-redis-auth -p 16480:6379 redis:alpine redis-server --requirepass secretpw \
        --user worker on '>workerpw' '~*' '&*' '+@all' --user encoded on '>p@ss:w/rd+' '~*' '&*' '+@all'
    start compat-queue-nats -p 14422:4222 nats:2.11-alpine -js
    start compat-queue-cluster-php -e INITIAL_PORT=17000 -e MASTERS=3 -e SLAVES_PER_MASTER=0 grokzen/redis-cluster:7.0.10
    start compat-queue-cluster-rust -e IP=127.0.0.1 -e INITIAL_PORT=17200 -e MASTERS=3 -e SLAVES_PER_MASTER=0 \
        -p 17200-17202:17200-17202 grokzen/redis-cluster:7.0.10
    # A cluster needs its slots assigned before it serves.
    for _ in $(seq 1 90); do
        if docker exec compat-queue-cluster-rust redis-cli -p 17200 cluster info 2>/dev/null | grep -q cluster_state:ok \
            && docker exec compat-queue-cluster-php redis-cli -p 17000 cluster info 2>/dev/null | grep -q cluster_state:ok; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-queue-redis-auth compat-queue-nats compat-queue-cluster-php compat-queue-cluster-rust >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
