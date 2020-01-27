let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.locale.getCurrencies();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});