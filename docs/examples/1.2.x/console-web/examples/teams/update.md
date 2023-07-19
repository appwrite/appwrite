import { Client, Teams } from "@appwrite.io/console";

const client = new Client();

const teams = new Teams(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = teams.update('[TEAM_ID]', '[NAME]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});