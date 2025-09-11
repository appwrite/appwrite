import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Databases databases = Databases(client);

AttributePolygon result = await databases.updatePolygonAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    xrequired: false,
    xdefault: [[[1, 2], [3, 4], [5, 6], [1, 2]]], // (optional)
    newKey: '', // (optional)
);
