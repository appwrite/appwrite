let sdk = new Appwrite();

let promise = sdk.account.updateVerification('[USER_ID]', '[SECRET]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});