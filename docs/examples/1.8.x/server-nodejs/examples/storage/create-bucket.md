const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const storage = new sdk.Storage(client);

const result = await storage.createBucket({
    bucketId: '<BUCKET_ID>',
    name: '<NAME>',
    permissions: ["read("any")"],
    fileSecurity: false,
    enabled: false,
    maximumFileSize: 1,
    allowedFileExtensions: [],
    compression: sdk..None,
    encryption: false,
    antivirus: false
});
