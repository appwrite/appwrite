import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;


let promise = account.deleteSession('[SESSION_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});