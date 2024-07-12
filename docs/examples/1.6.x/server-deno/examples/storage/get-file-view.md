import { Client, Storage } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new Storage(client);

const result = storage.getFileView(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>' // fileId
);
