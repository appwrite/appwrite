import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateTextmagicProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>', // optional
    enabled: false, // optional
    username: '<USERNAME>', // optional
    apiKey: '<API_KEY>', // optional
    from: '<FROM>' // optional
});

console.log(result);
