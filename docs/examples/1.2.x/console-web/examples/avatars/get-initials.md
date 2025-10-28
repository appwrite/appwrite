import { Client, Avatars } from "@appwrite.io/console";

const client = new Client();

const avatars = new Avatars(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

const result = avatars.getInitials();

console.log(result); // Resource URL