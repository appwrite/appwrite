import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let teams = new sdk.Teams(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;


let promise = teams.updateMembershipStatus('[TEAM_ID]', '[MEMBERSHIP_ID]', '[USER_ID]', '[SECRET]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});