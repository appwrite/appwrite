let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.list();

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});