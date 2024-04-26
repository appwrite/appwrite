import { Client, Storage } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const storage = new Storage(client);

const result = await storage.createFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    document.getElementById('uploader').files[0], // file
    ["read("any")"] // permissions (optional)
);

console.log(response);
