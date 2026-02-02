import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Databases databases = Databases(client);

AttributeVarchar result = await databases.createVarcharAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    size: 1,
    xrequired: false,
    xdefault: '<DEFAULT>', // (optional)
    array: false, // (optional)
);
