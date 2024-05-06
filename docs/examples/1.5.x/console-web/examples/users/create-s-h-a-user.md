import { Client, Users, PasswordHash } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const users = new Users(client);

const result = await users.createSHAUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    PasswordHash.Sha1, // passwordVersion (optional)
    '<NAME>' // name (optional)
);

console.log(response);
