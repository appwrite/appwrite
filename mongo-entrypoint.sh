#!/bin/bash
set -e

# Fix keyfile permissions if mounted from volume
KEYFILE_PATH="/data/keyfile/mongo-keyfile"

if [ ! -f "$KEYFILE_PATH" ]; then
  echo "Generating random MongoDB keyfile..."
  mkdir -p /data/keyfile
  openssl rand -base64 756 > "$KEYFILE_PATH"
fi

chmod 400 "$KEYFILE_PATH"
chown mongodb:mongodb "$KEYFILE_PATH" 2>/dev/null || chown 999:999 "$KEYFILE_PATH"

set -- mongod --replSet rs0 --bind_ip_all --auth --keyFile "$KEYFILE_PATH"

# An initialised data directory starts no temporary server, so there is nothing
# to race and the standard entrypoint can have the process.
if [ -n "$(ls -A /data/db 2>/dev/null)" ]; then
  exec docker-entrypoint.sh "$@"
fi

# First boot only.
#
# The standard entrypoint runs a temporary server on 127.0.0.1:27017 to create
# the users and run /docker-entrypoint-initdb.d, then starts the real server on
# 0.0.0.0:27017 without waiting for the temporary one to release the port. The
# real server spends around a second opening WiredTiger before it binds, so on a
# loaded machine it can lose by a few milliseconds and exit with
#
#   Error setting up transport layer ... 0.0.0.0:27017 :: caused by ::
#   setup bind :: caused by :: Address already in use
#
# taking the container with it, which fails every service that depends_on it
# under `docker compose up --wait`. Seen on a CI runner where the temporary
# server took 1126ms to exit after the real one had already started, losing the
# bind by 24ms; the same sequence takes 127ms on an idle machine and passes.
#
# The data directory is fully initialised by the time this happens, so starting
# again is enough: the second pass finds it populated, skips the temporary
# server, and binds once the port is free.
docker-entrypoint.sh "$@" &
server=$!
trap 'kill -TERM "$server" 2>/dev/null || true' TERM INT
wait "$server" || true
trap - TERM INT

echo "mongodb: first start did not survive initialisation, starting again" >&2

for _ in $(seq 1 30); do
  (exec 3<>/dev/tcp/127.0.0.1/27017) 2>/dev/null || break
  exec 3>&- 2>/dev/null || true
  sleep 1
done

exec docker-entrypoint.sh "$@"
