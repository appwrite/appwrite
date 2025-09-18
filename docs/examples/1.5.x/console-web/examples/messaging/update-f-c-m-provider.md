import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateFCMProvider(
    '[PROVIDER_ID]', // providerId
    '[NAME]', // name (optional)
    false, // enabled (optional)
    {} // serviceAccountJSON (optional)
);

console.log(response);
