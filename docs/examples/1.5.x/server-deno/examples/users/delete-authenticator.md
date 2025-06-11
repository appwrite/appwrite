import { Client, Users, AuthenticatorType } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

const users = new Users(client);

const response = await users.deleteAuthenticator(
    '<USER_ID>', // userId
    AuthenticatorType.Totp // type
);
