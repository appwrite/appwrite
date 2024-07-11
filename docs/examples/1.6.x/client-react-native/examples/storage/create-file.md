import { Client, Storage } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const storage = new Storage(client);

const result = await storage.createFile(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    await pickSingle(), // file
    ["read("any")"] // permissions (optional)
);

console.log(response);
