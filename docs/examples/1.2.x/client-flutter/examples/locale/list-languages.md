import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Locale locale = Locale(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;
  Future result = locale.listLanguages();

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
