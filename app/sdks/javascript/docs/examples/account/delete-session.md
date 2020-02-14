let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.account.deleteSession('[SESSION_UID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});