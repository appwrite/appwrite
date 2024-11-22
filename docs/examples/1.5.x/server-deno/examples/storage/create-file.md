import { Client, Storage } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new Storage(client);

const response = await storage.createFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    InputFile.fromPath('/path/to/file.png', 'file.png'), // file
    ["read("any")"] // permissions (optional)
);
