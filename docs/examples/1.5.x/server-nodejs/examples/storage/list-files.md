const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const storage = new sdk.Storage(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession('') // The user session to authenticate with
;

const promise = storage.listFiles('[BUCKET_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});