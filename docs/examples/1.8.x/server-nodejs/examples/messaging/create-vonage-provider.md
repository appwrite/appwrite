const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.createVonageProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    from: '+12065550100',
    apiKey: '<API_KEY>',
    apiSecret: '<API_SECRET>',
    enabled: false
});
