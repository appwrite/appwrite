import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.updateTwilioProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // (optional)
    enabled: false, // (optional)
    accountSid: '<ACCOUNT_SID>', // (optional)
    authToken: '<AUTH_TOKEN>', // (optional)
    from: '<FROM>', // (optional)
);
