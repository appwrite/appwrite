import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
  .setProject('5df5acd0d48c2'); // Your project ID

Account account = Account(client);

Future result = account.createOAuth2Session(
  provider:  OAuthProvider.amazon,
  success: 'https://example.com', // (optional)
  failure: 'https://example.com', // (optional)
  scopes: [], // (optional)
);

result.then((response) {
  print(response);
}).catchError((error) {
  print(error.response);
});
