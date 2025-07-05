import { Client, Storage } from "appwrite";

const client = new Client();

const storage = new Storage(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = storage.deleteFile('[BUCKET_ID]', '[FILE_ID]');

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});