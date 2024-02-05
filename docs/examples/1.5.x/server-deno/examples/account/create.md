import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let account = new sdk.Account(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;


let promise = account.create('[USER_ID]', 'email@example.com', '');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});