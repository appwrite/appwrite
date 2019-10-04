const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let teams = new sdk.Teams(client);

client
    .setProject('')
    .setKey('')
;

let promise = teams.updateTeamMembershipStatus('[TEAM_ID]', '[INVITE_ID]', '[USER_ID]', '[SECRET]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});