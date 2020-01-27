let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.avatars.getQR('[TEXT]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});