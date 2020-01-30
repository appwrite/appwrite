let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.createPlatform('[PROJECT_ID]', 'web', '[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});