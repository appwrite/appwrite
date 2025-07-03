import { Client, Tables } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tables = new Tables(client);

const result = await tables.createEnumColumn(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '', // key
    [], // elements
    false, // required
    '<DEFAULT>', // default (optional)
    false // array (optional)
);

console.log(result);
