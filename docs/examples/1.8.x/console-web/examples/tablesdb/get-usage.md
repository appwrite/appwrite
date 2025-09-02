import { Client, TablesDB, UsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tablesDB = new TablesDB(client);

const result = await tablesDB.getUsage({
    databaseId: '<DATABASE_ID>',
    range: UsageRange.TwentyFourHours // optional
});

console.log(result);
