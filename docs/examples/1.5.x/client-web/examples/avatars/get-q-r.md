import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getQR(
    '<TEXT>', // text
    1, // size (optional)
    0, // margin (optional)
    false // download (optional)
);

console.log(result);
