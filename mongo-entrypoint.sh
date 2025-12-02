#!/bin/bash
set -e

# Start MongoDB in the background
mongod --replSet rs0 --bind_ip_all &

# Wait for MongoDB to start
echo "Waiting for MongoDB to start..."
until mongosh --eval "print('MongoDB is ready')" > /dev/null 2>&1; do
    sleep 1
done

# Initialize replica set if not already initialized
echo "Initializing replica set..."
mongosh --eval "
try {
    rs.status();
    print('Replica set already initialized');
} catch (e) {
    rs.initiate({
        _id: 'rs0',
        members: [{ _id: 0, host: 'localhost:27017' }]
    });
    print('Replica set initialized');
}
"

# Wait for replica set to be ready
echo "Waiting for replica set to be ready..."
until mongosh --eval "rs.status().ok" > /dev/null 2>&1; do
    sleep 1
done

echo "MongoDB replica set is ready"

# Keep the container running by waiting on the MongoDB process
wait
