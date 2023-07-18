import { Client, Account } from "@appwrite.io/console";

const client = new Client();

const account = new Account(client);

client
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
;

// Go to OAuth provider login page
account.createOAuth2Session('amazon');

