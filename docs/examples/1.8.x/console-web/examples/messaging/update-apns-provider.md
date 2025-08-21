import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateApnsProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    authKey: '<AUTH_KEY>',
    authKeyId: '<AUTH_KEY_ID>',
    teamId: '<TEAM_ID>',
    bundleId: '<BUNDLE_ID>',
    sandbox: false
});

console.log(result);
