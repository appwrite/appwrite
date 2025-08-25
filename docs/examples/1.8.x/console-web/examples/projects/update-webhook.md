import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateWebhook({
    projectId: '<PROJECT_ID>',
    webhookId: '<WEBHOOK_ID>',
    name: '<NAME>',
    events: [],
    url: '',
    security: false,
    enabled: false,
    httpUser: '<HTTP_USER>',
    httpPass: '<HTTP_PASS>'
});

console.log(result);
