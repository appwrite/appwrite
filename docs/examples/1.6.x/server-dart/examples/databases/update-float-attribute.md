import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Databases databases = Databases(client);

AttributeFloat result = await databases.updateFloatAttribute(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    key: '',
    xrequired: false,
    min: 0,
    max: 0,
    xdefault: 0,
);
