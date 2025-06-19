import { Client, Tables } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tables = new Tables(client);

const response = await tables.upsertRows(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>' // tableId
);
