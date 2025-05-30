import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setKey(''); // 

Databases databases = Databases(client);

DocumentList result = await databases.createDocuments(
    databaseId: '<DATABASE_ID>',
    collectionId: '<COLLECTION_ID>',
    documents: [],
);
