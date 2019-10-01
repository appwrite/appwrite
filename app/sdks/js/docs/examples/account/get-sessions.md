let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.account.getSessions();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});