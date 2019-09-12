const sdk = require('node-appwrite');

// Init SDK
let client = new Storage.Client();

let storage = new sdk.Storage(client);

client
    setProject('')
    setKey('')
;

let promise = storage.getFilePreview('[FILE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});