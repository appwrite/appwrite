let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.auth.login('email@example.com', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});