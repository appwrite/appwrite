let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.createTeam('[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});