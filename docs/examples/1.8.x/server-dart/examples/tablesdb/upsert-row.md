import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

TablesDB tablesDB = TablesDB(client);

Row result = await tablesDB.upsertRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {}, // (optional)
    permissions: ["read("any")"], // (optional)
    transactionId: '<TRANSACTION_ID>', // (optional)
);
