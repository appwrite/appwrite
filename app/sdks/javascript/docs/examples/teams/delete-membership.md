let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.teams.deleteMembership('[TEAM_ID]', '[INVITE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});