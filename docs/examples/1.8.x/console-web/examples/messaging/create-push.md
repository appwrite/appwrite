import { Client, Messaging, MessagePriority } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createPush({
    messageId: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [],
    users: [],
    targets: [],
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
    priority: MessagePriority.Normal
});

console.log(result);
