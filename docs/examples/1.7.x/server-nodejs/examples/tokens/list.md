const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tokens = new sdk.Tokens(client);

const result = await tokens.list(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    [] // queries (optional)
);
