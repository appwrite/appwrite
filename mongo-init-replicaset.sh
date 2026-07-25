#!/bin/bash

# MongoDB Replica Set Initialization Script
# Runs after MongoDB starts as part of docker-entrypoint-initdb.d

# Check if replica set is already initialized
RS_STATUS=$(mongosh --username root --password "${MONGO_INITDB_ROOT_PASSWORD}" --authenticationDatabase admin --eval "try { rs.status().ok } catch(e) { 0 }" --quiet 2>/dev/null || echo "0")

if [ "$RS_STATUS" = "1" ]; then
    echo "Replica set already initialized"
    exit 0
fi

# Initialize replica set
echo "Initializing replica set 'rs0'..."
# For single-node replica set, we can use localhost since clients connect directly
# The MongoDB driver will handle connection to this node
mongosh --username root --password "${MONGO_INITDB_ROOT_PASSWORD}" --authenticationDatabase admin --eval "rs.initiate({ _id: 'rs0', members: [{ _id: 0, host: '127.0.0.1:27017' }] })"

# Wait for replica set to elect a primary and become stable
echo "Waiting for PRIMARY to be ready..."
MAX_WAIT=15
COUNTER=0
while [ $COUNTER -lt $MAX_WAIT ]; do
    # Use try/catch to handle cases where rs.status() fails during initialization
    PRIMARY_STATE=$(mongosh --username root --password "${MONGO_INITDB_ROOT_PASSWORD}" --authenticationDatabase admin --eval "try { rs.status().members[0].stateStr } catch(e) { 'WAITING' }" --quiet 2>/dev/null || echo "WAITING")

    if [ "$PRIMARY_STATE" = "PRIMARY" ]; then
        echo "PRIMARY is ready!"
        break
    fi

    # Give it more time between checks
    sleep 2
    COUNTER=$((COUNTER+1))

    # Only show status every few attempts to reduce log noise
    if [ $((COUNTER % 3)) -eq 0 ]; then
        echo "Still waiting for PRIMARY (attempt $COUNTER/$MAX_WAIT, current state: $PRIMARY_STATE)..."
    fi
done

# Don't fail the init even if PRIMARY isn't ready immediately
# MongoDB will continue to elect a primary in the background
# Clients will wait for it to be ready via their connection retry logic
if [ $COUNTER -eq $MAX_WAIT ]; then
    echo "NOTE: PRIMARY not confirmed within $((MAX_WAIT * 2)) seconds, but replica set is configured"
    echo "MongoDB will continue initializing the replica set in the background"
fi

echo "Replica set initialization complete!"
