import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updatePlatform({
    projectId: '<PROJECT_ID>',
    platformId: '<PLATFORM_ID>',
    name: '<NAME>',
    key: '<KEY>', // optional
    store: '<STORE>', // optional
    hostname: '' // optional
});

console.log(result);
