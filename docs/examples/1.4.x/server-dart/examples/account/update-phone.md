import 'package:dart_appwrite/dart_appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Account account = Account(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
  ;

  Future result = account.updatePhone(
    phone: '+12065550100',
    password: 'password',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}