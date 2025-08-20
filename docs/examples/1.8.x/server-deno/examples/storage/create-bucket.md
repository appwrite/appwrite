import { Client, Storage,  } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const storage = new Storage(client);

const response = await storage.createBucket({
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
