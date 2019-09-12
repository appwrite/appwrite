const sdk = require('node-appwrite');

// Init SDK
let client = new Database.Client();

let database = new sdk.Database(client);

client
    setProject('')
    setKey('')
;

let promise = database.createCollection('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});