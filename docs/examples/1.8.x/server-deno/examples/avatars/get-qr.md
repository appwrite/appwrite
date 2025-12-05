import { Client, Avatars } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const avatars = new Avatars(client);

const result = avatars.getQR({
    text: '<TEXT>',
    size: 1, // optional
    margin: 0, // optional
    download: false // optional
});
