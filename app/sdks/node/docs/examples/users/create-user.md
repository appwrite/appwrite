let sdk = new Appwrite();

sdk
    setProject('')
    setKey('')
;

let promise = sdk.users.createUser('email@example.com', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});