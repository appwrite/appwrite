import { Client, Avatars } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const avatars = new Avatars(client);

const result = avatars.getImage(
    'https://example.com', // url
    0, // width (optional)
    0 // height (optional)
);

console.log(result);
