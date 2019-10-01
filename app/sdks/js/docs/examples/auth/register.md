let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.auth.register('email@example.com', 'password', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});