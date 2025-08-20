import { Client, Messaging, MessagePriority } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.updatePush({
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
    priority: MessagePriority.Normal
});
