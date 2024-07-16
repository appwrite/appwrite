const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setSession(''); // The user session to authenticate with

const storage = new sdk.Storage(client);

const result = await storage.listFiles(
    '<BUCKET_ID>', // bucketId
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);
