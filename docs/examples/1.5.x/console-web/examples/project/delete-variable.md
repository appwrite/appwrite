import { Client, Project } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const project = new Project(client);

const result = await project.deleteVariable(
    '<VARIABLE_ID>' // variableId
);

console.log(response);
