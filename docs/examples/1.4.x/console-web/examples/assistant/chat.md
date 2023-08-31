import { Client, Assistant } from "@appwrite.io/console";

const client = new Client();

const assistant = new Assistant(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
;

const promise = assistant.chat('[PROMPT]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});