import { Client, Databases } from "@appwrite.io/console";

const client = new Client();

const databases = new Databases(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = databases.updateDatetimeAttribute('[DATABASE_ID]', '[COLLECTION_ID]', '', false, '');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});