import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const users = new Users(client);

const result = await users.deleteTarget(
    '<USER_ID>', // userId
    '<TARGET_ID>' // targetId
);

console.log(result);
