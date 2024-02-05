import * as sdk from "https://deno.land/x/appwrite/mod.ts";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;


let promise = messaging.createSubscriber('[TOPIC_ID]', '[SUBSCRIBER_ID]', '[TARGET_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});