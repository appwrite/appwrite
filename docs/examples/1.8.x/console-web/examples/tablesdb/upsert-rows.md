import { Client, TablesDB } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.upsertRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [],
    transactionId: '<TRANSACTION_ID>' // optional
});

console.log(result);
