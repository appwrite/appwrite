import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey('<YOUR_API_KEY>'); // Your secret API key

Tables tables = Tables(client);

RowList result = await tables.upsertRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
);
