import { Client, Users } from "@appwrite.io/console";

const client = new Client();

const users = new Users(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = users.updateEmail('[USER_ID]', 'email@example.com');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});