import { Client, Projects } from "appwrite";

const client = new Client();

const projects = new Projects(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = projects.create('[PROJECT_ID]', '[NAME]', '[TEAM_ID]', 'default');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});