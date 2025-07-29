
//const db = db.getSiblingDB('appwrite');
const db = db.getSiblingDB('admin');
db.createUser({
  user: 'user',
  pwd: 'password',
  roles: [
    { role: 'readWrite', db: 'appwrite' }
  ]
}); 