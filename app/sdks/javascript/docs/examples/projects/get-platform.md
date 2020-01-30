let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.getPlatform('[PROJECT_ID]', '[PLATFORM_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});