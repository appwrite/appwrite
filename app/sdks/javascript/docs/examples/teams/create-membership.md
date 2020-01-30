let sdk = new Appwrite();

sdk
    .setProject('')
    .setKey('')
;

let promise = sdk.teams.createMembership('[TEAM_ID]', 'email@example.com', [], 'https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});