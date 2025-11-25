import { Client, Projects, Api } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateAPIStatus({
    projectId: '<PROJECT_ID>',
    api: Api.Rest,
    status: false
});

console.log(result);
