import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const result = await account.createMagicURLToken(
    '<USER_ID>', // userId
    'email@example.com', // email
    'https://example.com', // url (optional)
    false // phrase (optional)
);

console.log(result);
