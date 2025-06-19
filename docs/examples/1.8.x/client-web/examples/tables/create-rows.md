import { Client, Tables } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey(''); // 

const tables = new Tables(client);

const result = await tables.createRows(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    [] // rows
);

console.log(result);
