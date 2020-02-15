let sdk = new Appwrite();

sdk
    .setProject('5df5acd0d48c2')
;

let promise = sdk.database.updateDocument('[COLLECTION_ID]', '[DOCUMENT_ID]', {}, [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});