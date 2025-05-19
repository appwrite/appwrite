import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createMD5User(
    '<USER_ID>', // userId
    'email@example.com', // email
    'password', // password
    '<NAME>' // name (optional)
);

console.log(result);
