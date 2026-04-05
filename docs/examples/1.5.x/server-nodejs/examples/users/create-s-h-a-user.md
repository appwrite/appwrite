const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.createSHAUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    sdk.PasswordHash.Sha1, // passwordVersion (optional)
    '<NAME>' // name (optional)
);
