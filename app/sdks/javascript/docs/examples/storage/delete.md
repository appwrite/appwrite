let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.storage.delete('[FILE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});