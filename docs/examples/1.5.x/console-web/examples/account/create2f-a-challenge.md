import { Client, Account, AuthenticationFactor } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const account = new Account(client);

const result = await account.create2FAChallenge(
    AuthenticationFactor.Totp // factor
);

console.log(response);
