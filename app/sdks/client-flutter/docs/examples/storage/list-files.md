import 'package:appwrite/appwrite.dart';

// Init SDK
Client client = Client();
Storage storage = Storage(client);

client
    .setProject('5df5acd0d48c2') // Your project ID
;

Future result = storage.listFiles(
);

result
  .then((response) {
    print(response);
  }).catchError((error) {
    print(error);
  });