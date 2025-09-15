import { Client, Storage } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new Storage(client);

const response = await storage.updateFile({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    name: '<NAME>', // optional
    permissions: ["read("any")"] // optional
});
