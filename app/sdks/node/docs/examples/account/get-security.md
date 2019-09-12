const sdk = require('node-appwrite');

// Init SDK
let client = new Account.Client();

let account = new sdk.Account(client);

client
    setProject('')
    setKey('')
;

let promise = account.getSecurity();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});