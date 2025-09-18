import { Client, Storage } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = storage.getFileView(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>' // fileId
);

console.log(result);
