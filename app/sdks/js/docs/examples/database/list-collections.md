let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.database.listCollections();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});