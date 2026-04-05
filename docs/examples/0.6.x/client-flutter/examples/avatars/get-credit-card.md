import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Avatars avatars = Avatars(client);

  client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;

  String result = avatars.getCreditCard(
    code: 'amex',
  );

  print(result); // Resource URL string
}