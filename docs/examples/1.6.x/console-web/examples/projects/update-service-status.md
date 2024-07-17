import { Client, Projects, ApiService } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateServiceStatus(
    '<PROJECT_ID>', // projectId
    ApiService.Account, // service
    false // status
);

console.log(response);
