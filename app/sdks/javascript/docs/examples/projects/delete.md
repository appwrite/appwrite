let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.delete('[PROJECT_ID]', '[PASSWORD]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});