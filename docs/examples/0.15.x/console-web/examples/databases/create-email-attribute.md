import { Client, Databases } from "appwrite";

const client = new Client();

const databases = new Databases(client, '[DATABASE_ID]');

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = databases.createEmailAttribute('[COLLECTION_ID]', '', false);

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});