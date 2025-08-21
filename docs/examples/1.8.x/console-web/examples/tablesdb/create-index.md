import { Client, TablesDb, IndexType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDb(client);

const result = await tablesDB.createIndex({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    key: '',
    type: IndexType.Key,
    columns: [],
    orders: [],
    lengths: []
});

console.log(result);
