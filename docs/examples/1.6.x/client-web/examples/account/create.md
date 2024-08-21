import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const account = new Account(client);

const result = await account.create(
    '<USER_ID>', // userId
    'email@example.com', // email
    '', // password
    '<NAME>' // name (optional)
);

console.log(result);
