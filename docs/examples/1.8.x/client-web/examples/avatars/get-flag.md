import { Client, Avatars, Flag } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getFlag({
    code: Flag.Afghanistan,
    width: 0, // optional
    height: 0, // optional
    quality: -1 // optional
});

console.log(result);
