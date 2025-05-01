import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Databases databases = Databases(client);

AttributeInteger result = await databases.updateIntegerAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    xrequired: false,
    xdefault: 0,
    min: 0, // (optional)
    max: 0, // (optional)
    newKey: '', // (optional)
);
