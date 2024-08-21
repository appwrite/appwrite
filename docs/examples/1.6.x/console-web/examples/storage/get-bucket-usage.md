import { Client, Storage, StorageUsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.getBucketUsage(
    '<BUCKET_ID>', // bucketId
    StorageUsageRange.TwentyFourHours // range (optional)
);

console.log(result);
