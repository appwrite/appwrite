const sdk = require('node-appwrite');

// Init SDK
let client = new Auth.Client();

let auth = new sdk.Auth(client);

client
    setProject('')
    setKey('')
;

let promise = auth.logoutBySession('[ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});