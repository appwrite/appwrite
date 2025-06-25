import { Client, Project } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const project = new Project(client);

const result = await project.updateVariable(
    '<VARIABLE_ID>', // variableId
    '<KEY>', // key
    '<VALUE>', // value (optional)
    false // secret (optional)
);

console.log(result);
