import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.createEmail(
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
