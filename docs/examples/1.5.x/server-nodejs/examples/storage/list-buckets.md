const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const storage = new sdk.Storage(client);

const result = await storage.listBuckets(
    [], // queries (optional)
    '<SEARCH>' // search (optional)
);
