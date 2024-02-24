import { Client, Storage,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const storage = new Storage(client);

const result = await storage.updateBucket(
    '<BUCKET_ID>', // bucketId
    '<NAME>', // name
    ["read("any")"], // permissions (optional)
    false, // fileSecurity (optional)
    false, // enabled (optional)
    1, // maximumFileSize (optional)
    [], // allowedFileExtensions (optional)
    .None, // compression (optional)
    false, // encryption (optional)
    false // antivirus (optional)
);

console.log(response);
