import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Health health = Health(client);

HealthQueue result = await health.getQueueDatabases(
    name: '<NAME>', // (optional)
    threshold: 0, // (optional)
);
