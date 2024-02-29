import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const users = new Users(client);

const result = await users.createScryptModifiedUser(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    '<PASSWORD_SALT>', // passwordSalt
    '<PASSWORD_SALT_SEPARATOR>', // passwordSaltSeparator
    '<PASSWORD_SIGNER_KEY>', // passwordSignerKey
    '<NAME>' // name (optional)
);

console.log(response);
