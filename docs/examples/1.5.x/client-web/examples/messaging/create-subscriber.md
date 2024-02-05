import { Client,  Messaging } from "appwrite";

const client = new Client();

const messaging = new Messaging(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = messaging.createSubscriber('[TOPIC_ID]', '[SUBSCRIBER_ID]', '[TARGET_ID]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});