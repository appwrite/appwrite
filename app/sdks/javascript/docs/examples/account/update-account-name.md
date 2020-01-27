let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.account.updateAccountName('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});