import { Client, Project } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const project = new Project(client);

const result = await project.updateVariable(
    '<VARIABLE_ID>', // variableId
    '<KEY>', // key
    '<VALUE>' // value (optional)
);

console.log(result);
