```javascript
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updatePush({
    messageId: '<MESSAGE_ID>',
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    title: '<TITLE>', // optional
    body: '<BODY>', // optional
    data: {}, // optional
    action: '<ACTION>', // optional
    image: '<ID1:ID2>', // optional
    icon: '<ICON>', // optional
    sound: '<SOUND>', // optional
    color: '<COLOR>', // optional
    tag: '<TAG>', // optional
    badge: null, // optional
    draft: false, // optional
    scheduledAt: '', // optional
    contentAvailable: false, // optional
    critical: false, // optional
    priority: sdk.MessagePriority.Normal // optional
});
```
