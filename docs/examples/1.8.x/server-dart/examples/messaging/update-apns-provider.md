import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Provider result = await messaging.updateAPNSProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // (optional)
    enabled: false, // (optional)
    authKey: '<AUTH_KEY>', // (optional)
    authKeyId: '<AUTH_KEY_ID>', // (optional)
    teamId: '<TEAM_ID>', // (optional)
    bundleId: '<BUNDLE_ID>', // (optional)
    sandbox: false, // (optional)
);
