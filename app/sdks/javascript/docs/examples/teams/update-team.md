let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.updateTeam('[TEAM_ID]', '[NAME]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});