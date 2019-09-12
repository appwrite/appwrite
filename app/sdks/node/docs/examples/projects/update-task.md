const sdk = require('node-appwrite');

// Init SDK
let client = new Projects.Client();

let projects = new sdk.Projects(client);

client
    setProject('')
    setKey('')
;

let promise = projects.updateTask('[PROJECT_ID]', '[TASK_ID]', '[NAME]', 'play', '', 0, 'GET', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});