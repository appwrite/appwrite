import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

TablesDB tablesDB = TablesDB(client);

RowList result = await tablesDB.updateRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    data: {}, // (optional)
    queries: [], // (optional)
);
