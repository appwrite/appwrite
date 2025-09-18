import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Messaging messaging = Messaging(client);

Topic result = await messaging.updateTopic(
    topicId: '<TOPIC_ID>',
    name: '<NAME>', // (optional)
    subscribe: ["any"], // (optional)
);
