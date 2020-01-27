let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.database.getCollection('[COLLECTION_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});