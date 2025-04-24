import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

Messaging messaging = Messaging(client);

await messaging.deleteSubscriber(
    topicId: '<TOPIC_ID>',
    subscriberId: '<SUBSCRIBER_ID>',
);
