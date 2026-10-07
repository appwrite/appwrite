#!/bin/bash
set -e

# Fix keyfile permissions
if [ -f "/tmp/keyfile" ]; then
  cp /tmp/keyfile /etc/mongo-keyfile
  chmod 400 /etc/mongo-keyfile
  chown mongodb:mongodb /etc/mongo-keyfile
fi

# Use MongoDB's standard entrypoint with our command
exec docker-entrypoint.sh mongod --replSet rs0 --bind_ip_all --auth --keyFile /etc/mongo-keyfile