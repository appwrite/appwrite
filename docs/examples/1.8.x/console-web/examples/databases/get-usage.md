import { Client, Databases, UsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.getUsage({
    databaseId: '<DATABASE_ID>',
    range: UsageRange.TwentyFourHours // optional
});

console.log(result);
