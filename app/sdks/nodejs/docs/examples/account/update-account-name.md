const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
    .setProject('')
;

let promise = account.updateAccountName('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});