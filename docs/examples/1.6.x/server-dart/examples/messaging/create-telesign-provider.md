import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.createTelesignProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    from: '+12065550100', // (optional)
    customerId: '<CUSTOMER_ID>', // (optional)
    apiKey: '<API_KEY>', // (optional)
    enabled: false, // (optional)
);
