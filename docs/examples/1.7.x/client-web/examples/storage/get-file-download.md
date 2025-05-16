import { Client, Storage } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = storage.getFileDownload(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    '<TOKEN>' // token (optional)
);

console.log(result);
