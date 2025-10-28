import { Client, Teams } from "@appwrite.io/console";

const client = new Client();

const teams = new Teams(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = teams.updateMembershipStatus('[TEAM_ID]', '[MEMBERSHIP_ID]', '[USER_ID]', '[SECRET]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});