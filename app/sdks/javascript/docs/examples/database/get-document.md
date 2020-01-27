let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.database.getDocument('[COLLECTION_ID]', '[DOCUMENT_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});