import { Client, Account, AuthenticationFactor } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const result = await account.createMfaChallenge(
    AuthenticationFactor.Email // factor
);

console.log(result);
