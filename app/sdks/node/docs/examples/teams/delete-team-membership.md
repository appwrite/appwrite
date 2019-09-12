const sdk = require('node-appwrite');

// Init SDK
let client = new Teams.Client();

let teams = new sdk.Teams(client);

client
    setProject('')
    setKey('')
;

let promise = teams.deleteTeamMembership('[TEAM_ID]', '[INVITE_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});