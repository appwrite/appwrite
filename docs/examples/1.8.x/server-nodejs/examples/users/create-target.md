```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.createTarget({
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    providerType: sdk.MessagingProviderType.Email,
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>', // optional
    name: '<NAME>' // optional
});
```
