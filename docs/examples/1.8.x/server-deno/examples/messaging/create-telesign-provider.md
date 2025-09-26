import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.createTelesignProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    from: '+12065550100', // optional
    customerId: '<CUSTOMER_ID>', // optional
    apiKey: '<API_KEY>', // optional
    enabled: false // optional
});
