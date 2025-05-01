import { Client, Storage,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.createBucket(
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

console.log(result);
