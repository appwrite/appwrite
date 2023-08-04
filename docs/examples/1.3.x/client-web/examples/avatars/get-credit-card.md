import { Client, Avatars } from "appwrite";

const client = new Client();

const avatars = new Avatars(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const result = avatars.getCreditCard('amex');

console.log(result); // Resource URL