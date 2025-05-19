import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Users users = Users(client);

Jwt result = await users.createJWT(
    userId: '<USER_ID>',
    sessionId: '<SESSION_ID>', // (optional)
    duration: 0, // (optional)
);
