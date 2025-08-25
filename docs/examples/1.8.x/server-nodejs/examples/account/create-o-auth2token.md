const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new sdk.Account(client);

const result = await account.createOAuth2Token({
    provider: sdk.OAuthProvider.Amazon,
    success: 'https://example.com',
    failure: 'https://example.com',
    scopes: []
});
