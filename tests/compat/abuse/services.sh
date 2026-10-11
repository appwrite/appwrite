#!/usr/bin/env bash
# Services the abuse cases need besides the dev stack's Redis: one Redis
# Cluster per runtime, as throwaway containers on the dev stack's network.
#
#   tests/compat/abuse/services.sh up     # start them
#   bin/compat check abuse
#   tests/compat/abuse/services.sh down   # stop them
#
# A cluster advertises the addresses its clients must dial, so the PHP
# driver's cluster announces its addresses on the network (17400-17405) and
# the Rust driver's 127.0.0.1:17500-17505, published on the host. Endpoints
# match tests/compat/compat.json. Redis itself is the dev stack's, databases
# 1 to 5.
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"

case "${1:-up}" in
up)
    docker run -d --rm --network "$network" --name compat-abuse-cluster-php -e INITIAL_PORT=17400 \
        grokzen/redis-cluster:7.0.10 >/dev/null
    docker run -d --rm --network "$network" --name compat-abuse-cluster-rust -e IP=127.0.0.1 -e INITIAL_PORT=17500 \
        -p 17500-17505:17500-17505 grokzen/redis-cluster:7.0.10 >/dev/null
    for _ in $(seq 1 90); do
        if docker exec compat-abuse-cluster-rust redis-cli -p 17500 cluster info 2>/dev/null | grep -q cluster_state:ok \
            && docker exec compat-abuse-cluster-php redis-cli -p 17400 cluster info 2>/dev/null | grep -q cluster_state:ok; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-abuse-cluster-php compat-abuse-cluster-rust >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
