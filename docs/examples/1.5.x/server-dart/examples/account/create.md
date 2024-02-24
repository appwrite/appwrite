import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
  .setProject('5df5acd0d48c2'); // Your project ID

Account account = Account(client);

Future result = account.create(
  userId: '<USER_ID>',
  email: 'email@example.com',
  password: '',
  name: '<NAME>', // (optional)
);

result.then((response) {
  print(response);
}).catchError((error) {
  print(error.response);
});
