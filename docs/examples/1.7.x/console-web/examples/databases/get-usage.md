import { Client, Databases, DatabaseUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const databases = new Databases(client);

const result = await databases.getUsage(
    DatabaseUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
