import { Client, Users, AuthenticatorType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const users = new Users(client);

const result = await users.deleteMfaAuthenticator(
    '<USER_ID>', // userId
    AuthenticatorType.Totp // type
);

console.log(response);
