import { Client, Storage,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const storage = new Storage(client);

const result = await storage.createBucket({
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: ["read("any")"],
    fileSecurity: false,
    enabled: false,
    maximumFileSize: 1,
    allowedFileExtensions: [],
    compression: .None,
    encryption: false,
    antivirus: false
});

console.log(result);
