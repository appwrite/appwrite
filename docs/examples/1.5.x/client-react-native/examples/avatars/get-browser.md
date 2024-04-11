import { Client, Avatars, Browser } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getBrowser(
    Browser.AvantBrowser, // code
    0, // width (optional)
    0, // height (optional)
    0 // quality (optional)
);

console.log(result);
