import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const users = new Users(client);

const result = await users.createToken(
    '<USER_ID>', // userId
    4, // length (optional)
    60 // expire (optional)
);

console.log(result);
