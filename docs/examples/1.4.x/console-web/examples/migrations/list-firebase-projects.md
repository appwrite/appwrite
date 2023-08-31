import { Client, Migrations } from "@appwrite.io/console";

const client = new Client();

const migrations = new Migrations(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
;

const promise = migrations.listFirebaseProjects();

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});