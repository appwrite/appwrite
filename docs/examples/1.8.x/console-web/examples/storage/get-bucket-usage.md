import { Client, Storage, UsageRange } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.getBucketUsage({
    bucketId: '<BUCKET_ID>',
    range: UsageRange.TwentyFourHours // optional
});

console.log(result);
