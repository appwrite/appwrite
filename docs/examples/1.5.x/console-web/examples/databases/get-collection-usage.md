import { Client, Databases, DatabaseUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const databases = new Databases(client);

const result = await databases.getCollectionUsage(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    DatabaseUsageRange.TwentyFourHours // range (optional)
);

console.log(response);
