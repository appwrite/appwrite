import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.create(
    '<USER_ID>', // userId
    'email@example.com', // email (optional)
    '+12065550100', // phone (optional)
    '', // password (optional)
    '<NAME>' // name (optional)
);

console.log(result);
