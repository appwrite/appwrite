let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.storage.createFile(document.getElementById('uploader').files[0], [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});