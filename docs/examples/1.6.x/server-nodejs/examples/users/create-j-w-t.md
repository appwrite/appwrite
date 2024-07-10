const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.createJWT(
    '<USER_ID>', // userId
    '<SESSION_ID>', // sessionId (optional)
    0 // duration (optional)
);
