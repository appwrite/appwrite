import { Client, Grids, DatabaseUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const grids = new Grids(client);

const result = await grids.getDatabaseUsage(
    '<DATABASE_ID>', // databaseId
    DatabaseUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
