import { Client, Tables } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setSession('') // The user session to authenticate with
    .setKey('<YOUR_API_KEY>') // Your secret API key
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

const tables = new Tables(client);

const response = await tables.upsertRow(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<ROW_ID>' // rowId
);
