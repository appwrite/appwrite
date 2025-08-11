// mongo-init.js

// Switch to the admin database
const db = db.getSiblingDB('admin');

// Get username and password from environment variables
const username = process.env.MONGO_INITDB_USERNAME;
const password = process.env.MONGO_INITDB_PASSWORD;
const database = process.env.MONGO_INITDB_DATABASE;

db.createUser({
  user: username,
  pwd: password,
  roles: [
    { role: 'readWrite', db: database }
  ]
});
