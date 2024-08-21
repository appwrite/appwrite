import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateAuthSessionsLimit(
    '<PROJECT_ID>', // projectId
    1 // limit
);

console.log(result);
