const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const account = new sdk.Account(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;

const promise = account.updateName('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});