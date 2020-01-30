let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.database.listCollections();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});