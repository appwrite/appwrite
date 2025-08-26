import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

TablesDB tablesDB = TablesDB(client);

RowList result = await tablesDB.listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [], // (optional)
);
