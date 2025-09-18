import { Client, Projects, AuthMethod } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const projects = new Projects(client);

const result = await projects.updateAuthStatus(
    '<PROJECT_ID>', // projectId
    AuthMethod.EmailPassword, // method
    false // status
);

console.log(result);
