import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateTextmagicProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    username: '<USERNAME>',
    apiKey: '<API_KEY>',
    from: '<FROM>'
});

console.log(result);
