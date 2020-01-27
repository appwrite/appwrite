const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let locale = new sdk.Locale(client);

client
    .setProject('')
;

let promise = locale.getContinents();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});