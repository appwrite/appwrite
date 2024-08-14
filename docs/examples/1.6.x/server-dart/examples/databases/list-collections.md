import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

Databases databases = Databases(client);

CollectionList result = await databases.listCollections(
    databaseId: '<DATABASE_ID>',
    queries: [], // (optional)
    search: '<SEARCH>', // (optional)
);
