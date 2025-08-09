import { Client, Grids } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const grids = new Grids(client);

const result = await grids.createRow(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<ROW_ID>', // rowId
    {}, // data
    ["read("any")"] // permissions (optional)
);

console.log(result);
