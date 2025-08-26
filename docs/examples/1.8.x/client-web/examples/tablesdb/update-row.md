import { Client, TablesDB } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.updateRow({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {}, // optional
    permissions: ["read("any")"] // optional
});

console.log(result);
