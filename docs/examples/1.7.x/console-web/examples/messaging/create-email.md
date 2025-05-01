import { Client, Messaging } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const messaging = new Messaging(client);

const result = await messaging.createEmail(
    '<MESSAGE_ID>', // messageId
    '<SUBJECT>', // subject
    '<CONTENT>', // content
    [], // topics (optional)
    [], // users (optional)
    [], // targets (optional)
    [], // cc (optional)
    [], // bcc (optional)
    [], // attachments (optional)
    false, // draft (optional)
    false, // html (optional)
    '' // scheduledAt (optional)
);

console.log(result);
