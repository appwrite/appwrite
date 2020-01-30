let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.createKey('[PROJECT_ID]', '[NAME]', []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});