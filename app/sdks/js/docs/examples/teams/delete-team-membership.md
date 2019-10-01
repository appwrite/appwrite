let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.deleteTeamMembership('[TEAM_ID]', '[INVITE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});