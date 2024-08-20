const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updateMailgunProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name (optional)
    '<API_KEY>', // apiKey (optional)
    '<DOMAIN>', // domain (optional)
    false, // isEuRegion (optional)
    false, // enabled (optional)
    '<FROM_NAME>', // fromName (optional)
    'email@example.com', // fromEmail (optional)
    '<REPLY_TO_NAME>', // replyToName (optional)
    '<REPLY_TO_EMAIL>' // replyToEmail (optional)
);
