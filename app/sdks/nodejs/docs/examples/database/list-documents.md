const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let database = new sdk.Database(client);

client
    .setProject('')
;

let promise = database.listDocuments('[COLLECTION_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});