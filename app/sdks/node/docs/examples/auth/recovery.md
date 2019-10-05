const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let auth = new sdk.Auth(client);

client
    .setProject('')
    .setKey('')
;

let promise = auth.recovery('email@example.com', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});