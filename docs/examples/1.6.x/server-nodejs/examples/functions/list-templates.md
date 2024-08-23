const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new sdk.Functions(client);

const result = await functions.listTemplates(
    [], // runtimes (optional)
    [], // useCases (optional)
    1, // limit (optional)
    0 // offset (optional)
);
