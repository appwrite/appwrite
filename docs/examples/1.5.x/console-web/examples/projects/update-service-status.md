import { Client, Projects, ApiService } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateServiceStatus(
    '<PROJECT_ID>', // projectId
    ApiService.Account, // service
    false // status
);

console.log(response);
