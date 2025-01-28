import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.getPlatform(
    '<PROJECT_ID>', // projectId
    '<PLATFORM_ID>' // platformId
);

console.log(result);
