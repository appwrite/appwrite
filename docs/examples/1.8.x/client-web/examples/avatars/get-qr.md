import { Client, Avatars } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getQR({
    text: '<TEXT>',
    size: 1, // optional
    margin: 0, // optional
    download: false // optional
});

console.log(result);
