import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Database database = Database(client);

  client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
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
      print(error.response);
  });
}
