let sdk = new Appwrite();

sdk
;

let promise = sdk.account.updateAccountRecovery('[USER_ID]', '[SECRET]', 'password', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});