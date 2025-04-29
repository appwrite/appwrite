import { Client, Storage } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const storage = new Storage(client);

const response = await storage.deleteBucket(
    '<BUCKET_ID>' // bucketId
);
