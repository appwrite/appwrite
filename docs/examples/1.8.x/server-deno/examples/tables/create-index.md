import { Client, Tables, IndexType } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tables = new Tables(client);

const response = await tables.createIndex(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '', // key
    IndexType.Key, // type
    [], // columns
    [], // orders (optional)
    [] // lengths (optional)
);
