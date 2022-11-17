import { Client, Databases } from "packageName";

const client = new Client();

const databases = new Databases(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = databases.deleteCollection('[DATABASE_ID]', '[COLLECTION_ID]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});