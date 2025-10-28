const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const users = new sdk.Users(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const promise = users.listProviders('[USER_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});