let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.account.getAccountLogs();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});