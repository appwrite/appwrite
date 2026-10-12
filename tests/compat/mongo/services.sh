#!/usr/bin/env bash
# The MongoDB the mongo cases run against, as a throwaway container on the
# dev stack's network: the image of docker-compose.yml's `mongodb` service
# (mongo:8.2.5), as a single-node replica set (`rs0`) with authentication,
# as Appwrite runs it, so transactions work.
#
#   tests/compat/mongo/services.sh up     # start it
#   bin/compat check mongo
#   tests/compat/mongo/services.sh down   # stop it
#
# Endpoints match tests/compat/compat.json (`mongo`): the PHP driver reaches
# compat-mongo:27017 on the network, the Rust driver and the runner
# localhost:16017 (a direct connection: the replica set advertises its
# member by its network name). User `root`, password `password`. Each case
# works in its own database (`${ns}`).
#
# tests/compat/database/services.sh starts another one the same way
# (COMPAT_MONGO_NAME=compat-database-mongo COMPAT_MONGO_PORT=16018).
set -euo pipefail
network="${COMPAT_NETWORK:-appwrite}"
image="${COMPAT_MONGO_IMAGE:-mongo:8.2.5}"
name="${COMPAT_MONGO_NAME:-compat-mongo}"
port="${COMPAT_MONGO_PORT:-16017}"

case "${1:-up}" in
up)
    if ! docker ps --format '{{.Names}}' | grep -qx "$name"; then
        # A replica set with authentication needs a keyfile its members share;
        # a small cache keeps it within the dev VM's memory.
        docker run -d --rm --network "$network" --name "$name" -p "$port:27017" \
            -e MONGO_INITDB_ROOT_USERNAME=root -e MONGO_INITDB_ROOT_PASSWORD=password \
            --entrypoint bash "$image" -c '
                openssl rand -base64 756 > /etc/mongo-keyfile
                chmod 400 /etc/mongo-keyfile
                chown mongodb:mongodb /etc/mongo-keyfile
                exec docker-entrypoint.sh mongod --replSet rs0 --bind_ip_all --keyFile /etc/mongo-keyfile --wiredTigerCacheSizeGB 0.5
            ' >/dev/null
    fi
    for _ in $(seq 1 120); do
        state=$(docker exec "$name" mongosh -u root -p password --authenticationDatabase admin --quiet --eval "
            try {
                const hello = db.adminCommand({hello: 1});
                if (hello.isWritablePrimary && hello.setName === 'rs0') { print('ready'); quit(0); }
            } catch (e) {}
            try { rs.initiate({_id: 'rs0', members: [{_id: 0, host: '$name:27017'}]}); } catch (e) {}
            print('waiting');
        " 2>/dev/null || true)
        if [ "$state" = "ready" ]; then
            exit 0
        fi
        sleep 1
    done
    echo "$name did not become ready" >&2
    exit 1
    ;;
down)
    docker stop "$name" >/dev/null 2>&1 || true
    ;;
*)
    echo "usage: $0 up|down" >&2
    exit 2
    ;;
esac
