import { Client, Grids } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const grids = new Grids(client);

const result = await grids.updateTable(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<NAME>', // name
    ["read("any")"], // permissions (optional)
    false, // rowSecurity (optional)
    false // enabled (optional)
);

console.log(result);
