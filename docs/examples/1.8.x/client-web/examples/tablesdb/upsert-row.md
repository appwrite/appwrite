import { Client, TablesDB, Permission, Role } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.upsertRow({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {}, // optional
    permissions: [Permission.read(Role.any())], // optional
    transactionId: '<TRANSACTION_ID>' // optional
});

console.log(result);
