import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

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
