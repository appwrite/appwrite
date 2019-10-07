const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let storage = new sdk.Storage(client);

client
    .setProject('')
    .setKey('')
;

let promise = storage.listFiles();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});