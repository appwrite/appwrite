const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const tokens = new sdk.Tokens(client);

const result = await tokens.get(
    '<TOKEN_ID>' // tokenId
);
