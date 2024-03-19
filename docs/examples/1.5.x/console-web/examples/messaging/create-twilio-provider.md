import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createTwilioProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name
    '+12065550100', // from (optional)
    '<ACCOUNT_SID>', // accountSid (optional)
    '<AUTH_TOKEN>', // authToken (optional)
    false // enabled (optional)
);

console.log(response);
