import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateAPNSProvider(
    '[PROVIDER_ID]', // providerId
    '[NAME]', // name (optional)
    false, // enabled (optional)
    '[AUTH_KEY]', // authKey (optional)
    '[AUTH_KEY_ID]', // authKeyId (optional)
    '[TEAM_ID]', // teamId (optional)
    '[BUNDLE_ID]' // bundleId (optional)
);

console.log(response);
