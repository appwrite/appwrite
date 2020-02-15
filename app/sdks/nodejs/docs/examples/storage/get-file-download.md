const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let storage = new sdk.Storage(client);

client
    .setProject('5df5acd0d48c2')
    .setKey('919c2d18fb5d4...a2ae413da83346ad2')
;

let promise = storage.getFileDownload('[FILE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});