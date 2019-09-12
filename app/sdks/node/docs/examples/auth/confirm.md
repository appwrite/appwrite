const sdk = require('node-appwrite');

// Init SDK
let client = new Auth.Client();

let auth = new sdk.Auth(client);

client
;

let promise = auth.confirm('[USER_ID]', '[TOKEN]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});