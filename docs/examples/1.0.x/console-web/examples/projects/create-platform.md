import { Client, Projects } from "appwrite";

const client = new Client();

const projects = new Projects(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = projects.createPlatform('[PROJECT_ID]', 'web', '[NAME]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});