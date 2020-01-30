let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.account.updatePassword('password', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});