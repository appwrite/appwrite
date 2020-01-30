let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.updateOAuth('[PROJECT_ID]', 'bitbucket');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});