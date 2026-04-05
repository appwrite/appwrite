const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.deleteVariable(
    '<FUNCTION_ID>', // functionId
    '<VARIABLE_ID>' // variableId
);
