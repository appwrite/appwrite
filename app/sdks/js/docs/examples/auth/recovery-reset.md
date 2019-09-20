let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.auth.recoveryReset('[USER_ID]', '[TOKEN]', 'password', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});