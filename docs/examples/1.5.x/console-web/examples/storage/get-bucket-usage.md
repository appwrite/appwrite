import { Client, Storage, StorageUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const storage = new Storage(client);

const result = await storage.getBucketUsage(
    '<BUCKET_ID>', // bucketId
    StorageUsageRange.TwentyFourHours // range (optional)
);

console.log(response);
