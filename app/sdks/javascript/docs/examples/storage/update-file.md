let sdk = new Appwrite();

sdk
    .setProject('5df5acd0d48c2') // Your project ID
;

let promise = sdk.storage.updateFile('[FILE_ID]', [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});