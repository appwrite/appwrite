import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Message result = await messaging.updatePush(
    messageId: '<MESSAGE_ID>',
    topics: [], // (optional)
    users: [], // (optional)
    targets: [], // (optional)
    title: '<TITLE>', // (optional)
    body: '<BODY>', // (optional)
    data: {}, // (optional)
    action: '<ACTION>', // (optional)
    image: '[ID1:ID2]', // (optional)
    icon: '<ICON>', // (optional)
    sound: '<SOUND>', // (optional)
    color: '<COLOR>', // (optional)
    tag: '<TAG>', // (optional)
    badge: 0, // (optional)
    draft: false, // (optional)
    scheduledAt: '', // (optional)
    contentAvailable: false, // (optional)
    critical: false, // (optional)
    priority: MessagePriority.normal, // (optional)
);
