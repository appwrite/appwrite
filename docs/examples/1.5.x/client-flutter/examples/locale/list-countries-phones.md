import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
  .setProject('5df5acd0d48c2'); // Your project ID

Locale locale = Locale(client);

Future result = locale.listCountriesPhones();

result.then((response) {
  print(response);
}).catchError((error) {
  print(error.response);
});

