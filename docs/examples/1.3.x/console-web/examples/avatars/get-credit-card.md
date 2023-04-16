import { Client, Avatars } from "@appwrite.io/console";

const client = new Client();

const avatars = new Avatars(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const result = avatars.getCreditCard('amex');

console.log(result); // Resource URL