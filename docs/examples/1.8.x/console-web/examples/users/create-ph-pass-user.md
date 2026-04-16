import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createPHPassUser({
    userId: '<USER_ID>',
    email: 'email@example.com',
    password: 'password',
    name: '<NAME>' // optional
});

console.log(result);
