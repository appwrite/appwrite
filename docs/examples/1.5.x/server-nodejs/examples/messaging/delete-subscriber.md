const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setJWT('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') // Your secret JSON Web Token
;

const promise = messaging.deleteSubscriber('[TOPIC_ID]', '[SUBSCRIBER_ID]');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});