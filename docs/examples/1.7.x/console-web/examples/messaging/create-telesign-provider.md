import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createTelesignProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '+12065550100', // from (optional)
    '<CUSTOMER_ID>', // customerId (optional)
    '<API_KEY>', // apiKey (optional)
    false // enabled (optional)
);

console.log(result);
