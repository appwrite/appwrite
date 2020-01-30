let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.account.createVerification('https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});