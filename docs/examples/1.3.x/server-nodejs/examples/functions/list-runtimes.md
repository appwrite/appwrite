const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const functions = new sdk.Functions(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const promise = functions.listRuntimes();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});