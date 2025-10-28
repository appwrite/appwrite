import { Client, Assistant } from "@appwrite.io/console";

const client = new Client();

const assistant = new Assistant(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = assistant.chat('[PROMPT]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});