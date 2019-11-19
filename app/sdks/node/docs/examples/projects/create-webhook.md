const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let projects = new sdk.Projects(client);

client
    .setProject('')
    .setKey('')
;

let promise = projects.createWebhook('[PROJECT_ID]', '[NAME]', [], '[URL]', 0);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});