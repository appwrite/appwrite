const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const account = new sdk.Account(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession('') // The user session to authenticate with
;

const promise = account.listSessions();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});