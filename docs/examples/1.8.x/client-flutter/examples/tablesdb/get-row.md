import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

TablesDB tablesDB = TablesDB(client);

Row result = await tablesDB.getRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    queries: [], // optional
    transactionId: '<TRANSACTION_ID>', // optional
);
