let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.updatePlatform('[PROJECT_ID]', '[PLATFORM_ID]', '[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});