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

# Use MongoDB's standard entrypoint with our command.
# On first boot, the standard entrypoint's temporary init mongod may not have
# released port 27017 by the time the real mongod binds it, which makes mongod
# exit with code 48 (address already in use). Retry only on that exit code.
child=0
trap '[ "$child" -ne 0 ] && kill -TERM "$child" 2>/dev/null' TERM INT

for attempt in 1 2 3; do
  docker-entrypoint.sh mongod --replSet rs0 --bind_ip_all --auth --keyFile "$KEYFILE_PATH" &
  child=$!
  exit_code=0
  wait "$child" || exit_code=$?

  if [ "$exit_code" -ne 48 ]; then
    exit "$exit_code"
  fi

  echo "mongod could not bind port 27017 (exit code 48), retrying ($attempt/3)..."
  sleep 2
done

exit 48
