const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.createVonageProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '+12065550100', // from (optional)
    '<API_KEY>', // apiKey (optional)
    '<API_SECRET>', // apiSecret (optional)
    false // enabled (optional)
);
