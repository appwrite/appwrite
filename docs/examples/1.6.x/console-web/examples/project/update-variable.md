import { Client, Project } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const project = new Project(client);

const result = await project.updateVariable(
    '<VARIABLE_ID>', // variableId
    '<KEY>', // key
    '<VALUE>' // value (optional)
);

console.log(response);
