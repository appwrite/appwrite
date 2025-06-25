import { Client, Tables } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setSession('') // The user session to authenticate with
    .setKey('') // 
    .setJWT('<YOUR_JWT>'); // Your secret JSON Web Token

const tables = new Tables(client);

const result = await tables.upsertRow(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<ROW_ID>' // rowId
);

console.log(result);
