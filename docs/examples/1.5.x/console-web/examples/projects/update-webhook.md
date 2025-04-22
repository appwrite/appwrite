import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateWebhook(
    '<PROJECT_ID>', // projectId
    '<WEBHOOK_ID>', // webhookId
    '<NAME>', // name
    [], // events
    '', // url
    false, // security
    false, // enabled (optional)
    '<HTTP_USER>', // httpUser (optional)
    '<HTTP_PASS>' // httpPass (optional)
);

console.log(result);
