let sdk = new Appwrite();

sdk
;

let promise = sdk.auth.confirm('[USER_ID]', '[TOKEN]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});