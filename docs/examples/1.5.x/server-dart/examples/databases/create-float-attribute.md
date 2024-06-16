import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

Databases databases = Databases(client);

AttributeFloat result = await databases.createFloatAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    xrequired: false,
    min: 0, // (optional)
    max: 0, // (optional)
    xdefault: 0, // (optional)
    array: false, // (optional)
);
