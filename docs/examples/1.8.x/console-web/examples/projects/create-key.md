import { Client, Projects } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.createKey({
    projectId: '<PROJECT_ID>',
    name: '<NAME>',
    scopes: [],
    expire: '' // optional
});

console.log(result);
