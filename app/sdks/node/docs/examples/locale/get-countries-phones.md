const sdk = require('node-appwrite');

// Init SDK
let client = new Locale.Client();

let locale = new sdk.Locale(client);

client
    setProject('')
    setKey('')
;

let promise = locale.getCountriesPhones();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});