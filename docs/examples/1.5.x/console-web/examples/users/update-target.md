import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const users = new Users(client);

const result = await users.updateTarget(
    '<USER_ID>', // userId
    '<TARGET_ID>', // targetId
    '<IDENTIFIER>', // identifier (optional)
    '<PROVIDER_ID>', // providerId (optional)
    '<NAME>' // name (optional)
);

console.log(response);
