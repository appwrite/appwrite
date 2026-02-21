const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let functions = new sdk.Functions(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

let promise = functions.deleteDeployment('[FUNCTION_ID]', '[DEPLOYMENT_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});