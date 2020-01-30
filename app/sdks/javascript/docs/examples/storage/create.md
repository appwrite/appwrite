let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.storage.create(document.getElementById('uploader').files[0], [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});