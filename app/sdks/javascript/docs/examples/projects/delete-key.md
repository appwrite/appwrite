let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.deleteKey('[PROJECT_ID]', '[KEY_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});