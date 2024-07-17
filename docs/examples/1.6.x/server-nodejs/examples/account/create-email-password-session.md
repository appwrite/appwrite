const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const account = new sdk.Account(client);

const result = await account.createEmailPasswordSession(
    'email@example.com', // email
    'password' // password
);
