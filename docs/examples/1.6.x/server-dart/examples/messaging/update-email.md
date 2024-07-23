import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Messaging messaging = Messaging(client);

Message result = await messaging.updateEmail(
    messageId: '<MESSAGE_ID>',
    topics: [], // (optional)
    users: [], // (optional)
    targets: [], // (optional)
    subject: '<SUBJECT>', // (optional)
    content: '<CONTENT>', // (optional)
    draft: false, // (optional)
    html: false, // (optional)
    cc: [], // (optional)
    bcc: [], // (optional)
    scheduledAt: '', // (optional)
    attachments: [], // (optional)
);
