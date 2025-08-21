import { Client, TablesDb } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDb(client);

const result = await tablesDB.listColumns({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: []
});

console.log(result);
