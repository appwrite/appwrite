import { Client, Health } from "@appwrite.io/console";

const client = new Client();

const health = new Health(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = health.getQueueFunctions();

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});