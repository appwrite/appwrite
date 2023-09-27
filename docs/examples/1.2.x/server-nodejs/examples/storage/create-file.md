const sdk = require('node-appwrite');
const fs = require('fs');

// Init SDK
const client = new sdk.Client();

const storage = new sdk.Storage(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const promise = storage.createFile('[BUCKET_ID]', '[FILE_ID]', InputFile.fromPath('/path/to/file.png', 'file.png'));

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});