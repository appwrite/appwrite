const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession(''); // The user session to authenticate with

const functions = new sdk.Functions(client);

const result = await functions.createExecution(
    '<FUNCTION_ID>', // functionId
    '<BODY>', // body (optional)
    false, // async (optional)
    '<PATH>', // path (optional)
    sdk.ExecutionMethod.GET, // method (optional)
    {} // headers (optional)
);
