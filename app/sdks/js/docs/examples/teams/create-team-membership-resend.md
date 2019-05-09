let sdk = new Appwrite();

sdk
    setProject('')
    setKey('')
;

let promise = sdk.teams.createTeamMembershipResend('[TEAM_ID]', '[INVITE_ID]', 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});