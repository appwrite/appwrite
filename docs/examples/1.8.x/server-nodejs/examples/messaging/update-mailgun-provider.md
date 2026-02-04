```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updateMailgunProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    apiKey: '<API_KEY>', // optional
    domain: '<DOMAIN>', // optional
    isEuRegion: false, // optional
    enabled: false, // optional
    fromName: '<FROM_NAME>', // optional
    fromEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    replyToEmail: '<REPLY_TO_EMAIL>' // optional
});
```
