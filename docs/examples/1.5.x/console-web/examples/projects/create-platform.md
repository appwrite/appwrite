import { Client, Projects, PlatformType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const projects = new Projects(client);

const result = await projects.createPlatform(
    '<PROJECT_ID>', // projectId
    PlatformType.Web, // type
    '<NAME>', // name
    '<KEY>', // key (optional)
    '<STORE>', // store (optional)
    '' // hostname (optional)
);

console.log(response);
