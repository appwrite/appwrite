import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey(''); // 

Tables tables = Tables(client);

RowList result = await tables.createRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [],
);
