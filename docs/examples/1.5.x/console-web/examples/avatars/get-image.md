import { Client, Avatars } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getImage(
    'https://example.com', // url
    0, // width (optional)
    0 // height (optional)
);

console.log(result);
