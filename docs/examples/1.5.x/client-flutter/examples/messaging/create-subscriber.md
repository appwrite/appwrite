import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
  .setProject('5df5acd0d48c2'); // Your project ID

Messaging messaging = Messaging(client);

Future result = messaging.createSubscriber(
  topicId: '<TOPIC_ID>',
  subscriberId: '<SUBSCRIBER_ID>',
  targetId: '<TARGET_ID>',
);

result.then((response) {
  print(response);
}).catchError((error) {
  print(error.response);
});

