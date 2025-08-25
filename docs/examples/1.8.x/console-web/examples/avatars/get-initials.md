import { Client, Avatars } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getInitials({
    name: '<NAME>',
    width: 0,
    height: 0,
    background: ''
});

console.log(result);
