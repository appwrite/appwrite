import { Client, Tables, DatabaseUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tables = new Tables(client);

const result = await tables.getUsage(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    DatabaseUsageRange.24h // range (optional)
);

console.log(result);
