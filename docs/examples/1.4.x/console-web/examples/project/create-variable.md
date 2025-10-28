import { Client, Project } from "@appwrite.io/console";

const client = new Client();

const project = new Project(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = project.createVariable('[KEY]', '[VALUE]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});