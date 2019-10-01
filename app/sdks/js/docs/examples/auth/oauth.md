let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.auth.oauth('bitbucket');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});