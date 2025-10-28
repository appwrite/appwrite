import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateApnsProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name (optional)
    false, // enabled (optional)
    '<AUTH_KEY>', // authKey (optional)
    '<AUTH_KEY_ID>', // authKeyId (optional)
    '<TEAM_ID>', // teamId (optional)
    '<BUNDLE_ID>', // bundleId (optional)
    false // sandbox (optional)
);

console.log(result);
