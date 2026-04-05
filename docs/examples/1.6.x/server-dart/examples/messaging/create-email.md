import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

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
