import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateMsg91Provider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    enabled: false, // optional
    templateId: '<TEMPLATE_ID>', // optional
    senderId: '<SENDER_ID>', // optional
    authKey: '<AUTH_KEY>' // optional
});

console.log(result);
