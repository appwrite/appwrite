const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const account = new sdk.Account(client);

const result = await account.createChallenge(
    sdk.AuthenticationFactor.Totp // factor
);
