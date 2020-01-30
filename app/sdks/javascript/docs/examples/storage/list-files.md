let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.storage.listFiles();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});