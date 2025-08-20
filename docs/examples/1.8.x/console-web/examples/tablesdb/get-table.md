import { Client, TablesDb } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDb = new TablesDb(client);

const result = await tablesDb.getTable({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>'
});

console.log(result);
