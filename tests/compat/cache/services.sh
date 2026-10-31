#!/usr/bin/env bash
# Services the cache cases need besides the dev stack's Redis, as throwaway
# containers on the dev stack's network, and a PHP driver image with the
# Memcached extension (the dev image lacks it):
#
#   tests/compat/cache/services.sh up     # start them (and build the image)
#   COMPAT_IMAGE=compat-cache-php bin/compat check cache
#   tests/compat/cache/services.sh down   # stop them
#
# Endpoints match tests/compat/compat.json: Memcached on 16392, Hazelcast's
# Memcached endpoint on 16393, and one Redis Cluster per runtime (the PHP
# driver's announces its addresses on the network, the Rust driver's
# 127.0.0.1:17100-17105), since a cluster advertises the addresses its
# clients must dial. Redis itself is the dev stack's, databases 6-15.
set -euo pipefail
cd "$(dirname "$0")"
network="${COMPAT_NETWORK:-appwrite}"

case "${1:-up}" in
up)
    docker build -q -t compat-cache-php --build-arg "BASE=${COMPAT_BASE_IMAGE:-appwrite-dev}" -f php.Dockerfile . >/dev/null
    docker run -d --rm --network "$network" --name compat-cache-memcached -p 16392:11211 memcached:1.6.17-alpine >/dev/null
    docker run -d --rm --network "$network" --name compat-cache-hazelcast -e HZ_NETWORK_MEMCACHEPROTOCOL_ENABLED=true \
        -p 16393:5701 hazelcast/hazelcast:5.3.7 >/dev/null
    docker run -d --rm --network "$network" --name compat-cache-cluster-php -e INITIAL_PORT=17000 \
        grokzen/redis-cluster:7.0.10 >/dev/null
    docker run -d --rm --network "$network" --name compat-cache-cluster-rust -e IP=127.0.0.1 -e INITIAL_PORT=17100 \
        -p 17100-17105:17100-17105 grokzen/redis-cluster:7.0.10 >/dev/null
    # Hazelcast serves the Memcached protocol a while after it starts, and a
    # cluster needs its slots assigned. Up to four minutes: on a CI runner
    # starting every library's services at once, Hazelcast's JVM has taken
    # longer than 90 seconds.
    for _ in $(seq 1 240); do
        if docker exec compat-cache-cluster-rust redis-cli -p 17100 cluster info 2>/dev/null | grep -q cluster_state:ok \
            && docker exec compat-cache-cluster-php redis-cli -p 17000 cluster info 2>/dev/null | grep -q cluster_state:ok \
            && docker logs compat-cache-hazelcast 2>&1 | grep -q "is STARTED"; then
            exit 0
        fi
        sleep 1
    done
    echo "services did not become ready" >&2
    exit 1
    ;;
down)
    docker stop compat-cache-memcached compat-cache-hazelcast compat-cache-cluster-php compat-cache-cluster-rust >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
