import { Client, Functions } from "@appwrite.io/console";

const client = new Client();

const functions = new Functions(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = functions.createDeployment('[FUNCTION_ID]', document.getElementById('uploader').files[0], false);

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});