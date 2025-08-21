const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updateApnsProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    authKey: '<AUTH_KEY>',
    authKeyId: '<AUTH_KEY_ID>',
    teamId: '<TEAM_ID>',
    bundleId: '<BUNDLE_ID>',
    sandbox: false
});
