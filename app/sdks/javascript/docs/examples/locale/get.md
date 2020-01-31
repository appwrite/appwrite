let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.locale.get();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});