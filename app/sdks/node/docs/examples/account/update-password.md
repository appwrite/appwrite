const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
    .setProject('')
    .setKey('')
;

let promise = account.updatePassword('password', 'password');
//First parameter is the new password and the second parameter is the old password in the above function.

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
