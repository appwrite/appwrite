import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.updateMailgunProvider(
    '<PROVIDER_ID>', // providerId
    '<NAME>', // name (optional)
    '<API_KEY>', // apiKey (optional)
    '<DOMAIN>', // domain (optional)
    false, // isEuRegion (optional)
    false, // enabled (optional)
    '<FROM_NAME>', // fromName (optional)
    'email@example.com', // fromEmail (optional)
    '<REPLY_TO_NAME>', // replyToName (optional)
    '<REPLY_TO_EMAIL>' // replyToEmail (optional)
);

console.log(response);
