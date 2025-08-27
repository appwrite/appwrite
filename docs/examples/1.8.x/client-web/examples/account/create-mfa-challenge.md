import { Client, Account, AuthenticationFactor } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const result = await account.createMFAChallenge({
    factor: AuthenticationFactor.Email
});

console.log(result);
