const sdk = require('node-appwrite');
const fs = require('fs');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

const functions = new sdk.Functions(client);

const result = await functions.createDeployment(
    '<FUNCTION_ID>', // functionId
    InputFile.fromPath('/path/to/file.png', 'file.png'), // code
    false, // activate
    '<ENTRYPOINT>', // entrypoint (optional)
    '<COMMANDS>' // commands (optional)
);
