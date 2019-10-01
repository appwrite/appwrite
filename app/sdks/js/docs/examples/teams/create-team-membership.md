let sdk = new Appwrite();

sdk
    .setProject('')
;

let promise = sdk.teams.createTeamMembership('[TEAM_ID]', 'email@example.com', [], 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});