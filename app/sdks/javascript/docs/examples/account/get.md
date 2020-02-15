let sdk = new Appwrite();

sdk
    .setProject('5df5acd0d48c2')
;

let promise = sdk.account.get();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});