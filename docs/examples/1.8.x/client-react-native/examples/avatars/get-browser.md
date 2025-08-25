import { Client, Avatars, Browser } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getBrowser({
    code: Browser.AvantBrowser,
    width: 0,
    height: 0,
    quality: -1
});

console.log(result);
