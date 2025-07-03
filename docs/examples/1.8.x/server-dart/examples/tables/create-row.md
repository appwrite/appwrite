import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setSession('') // The user session to authenticate with
    .setKey('<YOUR_API_KEY>') // Your secret API key
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

Tables tables = Tables(client);

Row result = await tables.createRow(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {},
    permissions: ["read("any")"], // (optional)
);
