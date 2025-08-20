import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    apiKey: '<API_KEY>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: 'email@example.com',
    enabled: false
});

console.log(result);
