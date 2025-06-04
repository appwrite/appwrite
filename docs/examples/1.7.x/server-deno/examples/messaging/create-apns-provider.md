import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.createApnsProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '<AUTH_KEY>', // authKey (optional)
    '<AUTH_KEY_ID>', // authKeyId (optional)
    '<TEAM_ID>', // teamId (optional)
    '<BUNDLE_ID>', // bundleId (optional)
    false, // sandbox (optional)
    false // enabled (optional)
);
