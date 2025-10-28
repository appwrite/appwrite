import { Client, Account, AuthenticationFactor } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const response = await account.createMfaChallenge(
    AuthenticationFactor.Email // factor
);
