import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

Messaging messaging = Messaging(client);

Subscriber result = await messaging.createSubscriber(
    topicId: '<TOPIC_ID>',
    subscriberId: '<SUBSCRIBER_ID>',
    targetId: '<TARGET_ID>',
);
