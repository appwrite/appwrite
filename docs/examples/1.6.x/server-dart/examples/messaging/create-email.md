import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Messaging messaging = Messaging(client);

Message result = await messaging.createEmail(
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [], // (optional)
    users: [], // (optional)
    targets: [], // (optional)
    cc: [], // (optional)
    bcc: [], // (optional)
    attachments: [], // (optional)
    draft: false, // (optional)
    html: false, // (optional)
    scheduledAt: '', // (optional)
);
