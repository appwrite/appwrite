let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.create('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});