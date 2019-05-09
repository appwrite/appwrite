let sdk = new Appwrite();

sdk
    setProject('')
    setKey('')
;

let promise = sdk.auth.confirmResend('https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});