import { Client, Functions } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const functions = new Functions(client);

const result = await functions.getExecution(
    '<FUNCTION_ID>', // functionId
    '<EXECUTION_ID>' // executionId
);

console.log(response);
