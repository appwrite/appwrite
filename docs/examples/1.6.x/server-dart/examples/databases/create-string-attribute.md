import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Databases databases = Databases(client);

AttributeString result = await databases.createStringAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    size: 1,
    xrequired: false,
    xdefault: '<DEFAULT>', // (optional)
    array: false, // (optional)
    encrypt: false, // (optional)
);
