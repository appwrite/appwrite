import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.updateVonageProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    apiKey: '<API_KEY>',
    apiSecret: '<API_SECRET>',
    from: '<FROM>'
});
