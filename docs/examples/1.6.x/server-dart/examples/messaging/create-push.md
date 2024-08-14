import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Messaging messaging = Messaging(client);

Message result = await messaging.createPush(
    messageId: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [], // (optional)
    users: [], // (optional)
    targets: [], // (optional)
    data: {}, // (optional)
    action: '<ACTION>', // (optional)
    image: '[ID1:ID2]', // (optional)
    icon: '<ICON>', // (optional)
    sound: '<SOUND>', // (optional)
    color: '<COLOR>', // (optional)
    tag: '<TAG>', // (optional)
    badge: '<BADGE>', // (optional)
    draft: false, // (optional)
    scheduledAt: '', // (optional)
);
