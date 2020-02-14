let sdk = new Appwrite();

let promise = sdk.account.create('email@example.com', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});