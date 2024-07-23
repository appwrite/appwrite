import { Client, Messaging } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const messaging = new Messaging(client);

const response = await messaging.updateEmail(
    '<MESSAGE_ID>', // messageId
    [], // topics (optional)
    [], // users (optional)
    [], // targets (optional)
    '<SUBJECT>', // subject (optional)
    '<CONTENT>', // content (optional)
    false, // draft (optional)
    false, // html (optional)
    [], // cc (optional)
    [], // bcc (optional)
    '', // scheduledAt (optional)
    [] // attachments (optional)
);
