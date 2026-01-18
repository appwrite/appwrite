import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const account = new Account(client);

const result = await account.createEmailToken({
    userId: '<USER_ID>',
    email: 'email@example.com',
    phrase: false // optional
});

console.log(result);
