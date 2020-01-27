const sdk = require('node-appwrite');

// Init SDK
let client = new sdk.Client();

let teams = new sdk.Teams(client);

client
    .setProject('')
;

let promise = teams.deleteTeamMembership('[TEAM_ID]', '[INVITE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});