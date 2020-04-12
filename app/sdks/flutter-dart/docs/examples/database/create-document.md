import 'package:appwrite/appwrite.dart';

// Init SDK
Client client = Client();
Database database = Database(client);

client
    .setProject('5df5acd0d48c2') // Your project ID
;

Future result = database.createDocument(
    collectionId: '[COLLECTION_ID]',
    data: {},
    read: [],
    write: [],
);

result
  .then((response) {
    print(response);
  }).catchError((error) {
    print(error);
  });