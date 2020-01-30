let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.updateTask('[PROJECT_ID]', '[TASK_ID]', '[NAME]', 'play', '', 0, 'GET', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});