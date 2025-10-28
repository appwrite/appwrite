import { Client, Projects } from "@appwrite.io/console";

const client = new Client();

const projects = new Projects(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = projects.getKey('[PROJECT_ID]', '[KEY_ID]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});