import { Client, TablesDB } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tablesDB = new TablesDB(client);

const response = await tablesDB.createUrlColumn({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    key: '',
    required: false,
    default: 'https://example.com', // optional
    array: false // optional
});
