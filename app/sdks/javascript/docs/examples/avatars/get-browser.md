let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.avatars.getBrowser('aa');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});