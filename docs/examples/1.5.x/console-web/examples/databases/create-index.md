import { Client, IndexType, Databases } from "@appwrite.io/console";

const client = new Client();

const databases = new Databases(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const promise = databases.createIndex('[DATABASE_ID]', '[COLLECTION_ID]', '', IndexType.Key, []);

promise.then(function (response) {
    console.log(response); // Success
}, function (error) {
    console.log(error); // Failure
});