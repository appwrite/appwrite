const sdk = require('node-appwrite');

// Init SDK
let client = new Avatars.Client();

let avatars = new sdk.Avatars(client);

client
    .setProject('')
    .setKey('')
;

let promise = avatars.getImage('https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});