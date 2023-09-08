import 'package:dart_appwrite/dart_appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Databases databases = Databases(client);

  client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = databases.createRelationshipAttribute(
    databaseId: '[DATABASE_ID]',
    collectionId: '[COLLECTION_ID]',
    relatedCollectionId: '[RELATED_COLLECTION_ID]',
    type: 'oneToOne',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}