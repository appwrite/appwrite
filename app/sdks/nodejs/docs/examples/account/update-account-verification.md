const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
;

let promise = account.updateAccountVerification('[USER_ID]', '[SECRET]', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});