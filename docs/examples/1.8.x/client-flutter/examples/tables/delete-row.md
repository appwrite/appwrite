import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

Tables tables = Tables(client);

await tables.deleteRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
);
