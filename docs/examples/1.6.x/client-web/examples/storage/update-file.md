import { Client, Storage } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const storage = new Storage(client);

const result = await storage.updateFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    '<NAME>', // name (optional)
    ["read("any")"] // permissions (optional)
);

console.log(response);
