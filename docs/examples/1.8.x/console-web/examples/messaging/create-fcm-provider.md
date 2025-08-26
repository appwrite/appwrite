import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createFCMProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    serviceAccountJSON: {}, // optional
    enabled: false // optional
});

console.log(result);
