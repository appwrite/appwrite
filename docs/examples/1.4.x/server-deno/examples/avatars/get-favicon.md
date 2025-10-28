import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let avatars = new sdk.Avatars(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;


let promise = avatars.getFavicon('https://example.com');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});