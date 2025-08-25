const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.createSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    apiKey: '<API_KEY>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: 'email@example.com',
    enabled: false
});
