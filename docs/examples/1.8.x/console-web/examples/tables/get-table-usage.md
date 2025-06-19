import { Client, Tables,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tables = new Tables(client);

const result = await tables.getTableUsage(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    .24h // range (optional)
);

console.log(result);
