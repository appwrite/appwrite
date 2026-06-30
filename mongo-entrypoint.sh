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

# Initiate the single-node replica set in the background, decoupled from the
# healthcheck. We connect via the service hostname (not localhost) so we only
# ever reach the real, network-bound mongod -- never the temporary
# localhost-only instance that docker-entrypoint.sh spins up while creating the
# initial users on a fresh data directory. Tight polling (1s) instead of relying
# on the 10s healthcheck cadence removes most of the time-to-healthy variance.
(
  HOST="appwrite-mongodb:27017"

  # Wait until the real server accepts authenticated connections.
  until mongosh --host "$HOST" -u root -p "$MONGO_INITDB_ROOT_PASSWORD" \
        --authenticationDatabase admin --quiet --eval 'quit(0)' >/dev/null 2>&1; do
    sleep 1
  done

  # Initiate once; harmless no-op (caught) if already initiated.
  mongosh --host "$HOST" -u root -p "$MONGO_INITDB_ROOT_PASSWORD" \
    --authenticationDatabase admin --quiet --eval '
      try {
        rs.status();
      } catch (e) {
        rs.initiate({_id: "rs0", members: [{_id: 0, host: "appwrite-mongodb:27017"}]});
      }
    ' >/dev/null 2>&1 || true
) &

# Use MongoDB's standard entrypoint with our command
exec docker-entrypoint.sh mongod --replSet rs0 --bind_ip_all --auth --keyFile "$KEYFILE_PATH"
