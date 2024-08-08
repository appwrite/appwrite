import { Client, Account } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const account = new Account(client);

const result = await account.updateMagicURLSession(
    '<USER_ID>', // userId
    '<SECRET>' // secret
);

console.log(response);
