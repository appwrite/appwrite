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
# Run it as a child so that this one outcome can be retried.
docker-entrypoint.sh "$@" &
server=$!

signal=0
trap 'signal=15; kill -s TERM "$server" 2>/dev/null || true' TERM
trap 'signal=2; kill -s INT "$server" 2>/dev/null || true' INT

status=0
wait "$server" || status=$?

# A trapped signal interrupts `wait` and returns 128+signal without reaping the
# child, so wait again: mongod has to finish its own shutdown before this
# process, which is PID 1, leaves and takes the container with it.
while kill -0 "$server" 2>/dev/null; do
  status=0
  wait "$server" || status=$?
done

trap - TERM INT

# Asked to stop. Report it the way a process killed by the signal does instead
# of starting the server the caller just asked to go away.
if [ "$signal" -ne 0 ]; then
  exit $((128 + signal))
fi

# 48 is EXIT_NET_ERROR, what mongod exits with when it cannot bind, and the
# standard entrypoint ends in `exec "$@"` so it arrives unchanged. A temporary
# server that loses the bind instead fails inside `mongod --fork`, whose parent
# reports 1. Every other status has to be raised rather than retried, an
# initialisation script that failed once the storage files existed most of all:
# a second pass would find /data/db populated, skip /docker-entrypoint-initdb.d
# and serve a database with no application user, hiding why.
if [ "$status" -ne 48 ]; then
  exit "$status"
fi

echo "mongodb: lost 0.0.0.0:27017 to the initialisation server, starting again" >&2

# The data directory is fully initialised by now, so the second pass skips the
# temporary server and only has to wait for the port.
for _ in $(seq 1 30); do
  (exec 3<>/dev/tcp/127.0.0.1/27017) 2>/dev/null || break
  sleep 1
done

exec docker-entrypoint.sh "$@"
