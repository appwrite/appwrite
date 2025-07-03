import { Client, Avatars, Browser } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getBrowser(
    Browser.AvantBrowser, // code
    0, // width (optional)
    0, // height (optional)
    -1 // quality (optional)
);

console.log(result);
