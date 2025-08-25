const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new sdk.Messaging(client);

const result = await messaging.updatePush({
    messageId: '<MESSAGE_ID>',
    topics: [],
    users: [],
    targets: [],
    title: '<TITLE>',
    body: '<BODY>',
    data: {},
    action: '<ACTION>',
    image: '[ID1:ID2]',
    icon: '<ICON>',
    sound: '<SOUND>',
    color: '<COLOR>',
    tag: '<TAG>',
    badge: null,
    draft: false,
    scheduledAt: '',
    contentAvailable: false,
    critical: false,
    priority: sdk.MessagePriority.Normal
});
