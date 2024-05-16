import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateSms(
    '<MESSAGE_ID>', // messageId
    [], // topics (optional)
    [], // users (optional)
    [], // targets (optional)
    '<CONTENT>', // content (optional)
    false, // draft (optional)
    '' // scheduledAt (optional)
);

console.log(response);
