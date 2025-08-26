import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.createJWT({
    userId: '<USER_ID>',
    sessionId: '<SESSION_ID>', // optional
    duration: 0 // optional
});

console.log(result);
