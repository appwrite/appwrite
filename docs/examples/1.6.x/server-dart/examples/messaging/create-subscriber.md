import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setJWT('&lt;YOUR_JWT&gt;'); // Your secret JSON Web Token

Messaging messaging = Messaging(client);

Subscriber result = await messaging.createSubscriber(
    topicId: '<TOPIC_ID>',
    subscriberId: '<SUBSCRIBER_ID>',
    targetId: '<TARGET_ID>',
);
