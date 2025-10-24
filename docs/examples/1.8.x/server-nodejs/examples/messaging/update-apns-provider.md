const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updateAPNSProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    enabled: false, // optional
    authKey: '<AUTH_KEY>', // optional
    authKeyId: '<AUTH_KEY_ID>', // optional
    teamId: '<TEAM_ID>', // optional
    bundleId: '<BUNDLE_ID>', // optional
    sandbox: false // optional
});
