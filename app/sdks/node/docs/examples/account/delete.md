const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
    .setProject('')
    .setKey('')
;

let promise = account.delete();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});