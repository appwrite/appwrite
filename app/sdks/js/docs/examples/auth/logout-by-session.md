let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.auth.logoutBySession('[ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});