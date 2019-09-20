let sdk = new Appwrite();

sdk
    setProject('')
;

let promise = sdk.auth.logout();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});