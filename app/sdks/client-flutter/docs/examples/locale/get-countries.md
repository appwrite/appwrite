import 'package:appwrite/appwrite.dart';

// Init SDK
Client client = Client();
Locale locale = Locale(client);

client
    .setProject('5df5acd0d48c2') // Your project ID
;

Future result = locale.getCountries();

result
  .then((response) {
    print(response);
  }).catchError((error) {
    print(error);
  });