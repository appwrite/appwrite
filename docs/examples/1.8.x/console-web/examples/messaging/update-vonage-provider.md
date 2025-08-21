import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateVonageProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    apiKey: '<API_KEY>',
    apiSecret: '<API_SECRET>',
    from: '<FROM>'
});

console.log(result);
