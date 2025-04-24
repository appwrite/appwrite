import { Client, Storage } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new Storage(client);

const response = await storage.createFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    InputFile.fromPath('/path/to/file.png', 'file.png'), // file
    ["read("any")"] // permissions (optional)
);
