let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.projects.list();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});