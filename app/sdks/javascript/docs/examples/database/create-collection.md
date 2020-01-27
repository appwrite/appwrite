let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.database.createCollection('[NAME]', [], [], []);

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});