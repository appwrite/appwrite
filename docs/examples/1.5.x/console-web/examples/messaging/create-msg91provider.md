import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createMsg91Provider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '<TEMPLATE_ID>', // templateId (optional)
    '<SENDER_ID>', // senderId (optional)
    '<AUTH_KEY>', // authKey (optional)
    false // enabled (optional)
);

console.log(result);
