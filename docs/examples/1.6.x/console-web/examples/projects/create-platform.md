import { Client, Projects, PlatformType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.createPlatform(
    '<PROJECT_ID>', // projectId
    PlatformType.Web, // type
    '<NAME>', // name
    '<KEY>', // key (optional)
    '<STORE>', // store (optional)
    '' // hostname (optional)
);

console.log(result);
