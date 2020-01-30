let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.avatars.getCreditCard('amex');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});