import { Client, Users, AuthenticatorType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const users = new Users(client);

const result = await users.deleteMfaAuthenticator(
    '<USER_ID>', // userId
    AuthenticatorType.Totp // type
);

console.log(result);
