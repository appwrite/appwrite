import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.createSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    apiKey: '<API_KEY>', // optional
    fromName: '<FROM_NAME>', // optional
    fromEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    enabled: false // optional
});
