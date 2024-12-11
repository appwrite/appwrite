import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Account account = Account(client);

  client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;
  Future result = account.updatePhoneVerification(
    userId: '[USER_ID]',
    secret: '[SECRET]',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
