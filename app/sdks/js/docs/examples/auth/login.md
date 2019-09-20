let sdk = new Appwrite();

sdk
    setProject('')
    setKey('')
;

let promise = sdk.auth.login('email@example.com', 'password', 'https://example.com', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});