import { Client, Storage } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.createFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    document.getElementById('uploader').files[0], // file
    ["read("any")"] // permissions (optional)
);

console.log(result);
