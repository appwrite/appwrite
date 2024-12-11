import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updatePlatform(
    '<PROJECT_ID>', // projectId
    '<PLATFORM_ID>', // platformId
    '<NAME>', // name
    '<KEY>', // key (optional)
    '<STORE>', // store (optional)
    '' // hostname (optional)
);

console.log(result);
