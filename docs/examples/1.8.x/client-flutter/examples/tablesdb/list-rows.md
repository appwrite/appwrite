import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

TablesDB tablesDB = TablesDB(client);

RowList result = await tablesDB.listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [], // optional
    transactionId: '<TRANSACTION_ID>', // optional
    total: false, // optional
);
