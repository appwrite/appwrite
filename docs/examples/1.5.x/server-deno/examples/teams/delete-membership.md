import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let teams = new sdk.Teams(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setSession('') // The user session to authenticate with
;


let promise = teams.deleteMembership('[TEAM_ID]', '[MEMBERSHIP_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});