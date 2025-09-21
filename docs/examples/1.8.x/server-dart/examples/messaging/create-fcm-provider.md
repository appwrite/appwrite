import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.createFCMProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    serviceAccountJSON: {}, // (optional)
    enabled: false, // (optional)
);
