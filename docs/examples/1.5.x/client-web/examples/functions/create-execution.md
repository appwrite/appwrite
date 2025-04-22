import { Client, Functions, ExecutionMethod } from "appwrite";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.createExecution(
    '<FUNCTION_ID>', // functionId
    '<BODY>', // body (optional)
    false, // async (optional)
    '<PATH>', // path (optional)
    ExecutionMethod.GET, // method (optional)
    {}, // headers (optional)
    '' // scheduledAt (optional)
);

console.log(result);
