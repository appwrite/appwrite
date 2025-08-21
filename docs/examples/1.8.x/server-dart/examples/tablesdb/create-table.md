import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

TablesDb tablesDB = TablesDb(client);

Table result = await tablesDB.createTable(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    name: '<NAME>',
    permissions: ["read("any")"], // (optional)
    rowSecurity: false, // (optional)
    enabled: false, // (optional)
);
