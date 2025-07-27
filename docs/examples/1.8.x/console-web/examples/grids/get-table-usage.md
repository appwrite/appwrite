import { Client, Grids, GridUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const grids = new Grids(client);

const result = await grids.getTableUsage(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    GridUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
