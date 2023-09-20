import { Client, Vcs } from "@appwrite.io/console";

const client = new Client();

const vcs = new Vcs(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = vcs.updateExternalDeployments('[INSTALLATION_ID]', '[REPOSITORY_ID]', '[PROVIDER_PULL_REQUEST_ID]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});