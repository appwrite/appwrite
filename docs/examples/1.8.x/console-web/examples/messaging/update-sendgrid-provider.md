import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    apiKey: '<API_KEY>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: '<REPLY_TO_EMAIL>'
});

console.log(result);
