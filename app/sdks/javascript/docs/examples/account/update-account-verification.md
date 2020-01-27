let sdk = new Appwrite();

sdk
;

let promise = sdk.account.updateAccountVerification('[USER_ID]', '[SECRET]', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});