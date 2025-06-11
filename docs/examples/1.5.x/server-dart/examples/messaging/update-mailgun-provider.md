import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.updateMailgunProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // (optional)
    apiKey: '<API_KEY>', // (optional)
    domain: '<DOMAIN>', // (optional)
    isEuRegion: false, // (optional)
    enabled: false, // (optional)
    fromName: '<FROM_NAME>', // (optional)
    fromEmail: 'email@example.com', // (optional)
    replyToName: '<REPLY_TO_NAME>', // (optional)
    replyToEmail: '<REPLY_TO_EMAIL>', // (optional)
);
