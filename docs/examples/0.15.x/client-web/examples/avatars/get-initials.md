import { Client, Avatars } from "appwrite";

const client = new Client();

const avatars = new Avatars(client);

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const result = avatars.getInitials();

console.log(result); // Resource URL