import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Databases databases = Databases(client);

Index result = await databases.createIndex(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    type: enums.IndexType.key,
    attributes: [],
    orders: [enums.OrderBy.asc], // (optional)
    lengths: [], // (optional)
);
