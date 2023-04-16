import { Client, Account } from "@appwrite.io/console";

const client = new Client();

const account = new Account(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = account.createPhoneSession('[USER_ID]', '+12065550100');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});