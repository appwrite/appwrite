const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.createMsg91Provider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '<TEMPLATE_ID>', // templateId (optional)
    '<SENDER_ID>', // senderId (optional)
    '<AUTH_KEY>', // authKey (optional)
    false // enabled (optional)
);
