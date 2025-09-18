import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Users users = Users(client);

Target result = await users.updateTarget(
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    identifier: '<IDENTIFIER>', // (optional)
    providerId: '<PROVIDER_ID>', // (optional)
    name: '<NAME>', // (optional)
);
