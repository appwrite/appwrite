let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.storage.updateFile('[FILE_ID]', [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});