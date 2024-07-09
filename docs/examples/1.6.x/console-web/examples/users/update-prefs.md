import { Client, Users } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const users = new Users(client);

const result = await users.updatePrefs(
    '<USER_ID>', // userId
    {} // prefs
);

console.log(response);
