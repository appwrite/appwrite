import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Message result = await messaging.createSms(
    messageId: '<MESSAGE_ID>',
    content: '<CONTENT>',
    topics: [], // (optional)
    users: [], // (optional)
    targets: [], // (optional)
    draft: false, // (optional)
    scheduledAt: '', // (optional)
);
