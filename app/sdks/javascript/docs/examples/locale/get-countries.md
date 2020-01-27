let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.locale.getCountries();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});