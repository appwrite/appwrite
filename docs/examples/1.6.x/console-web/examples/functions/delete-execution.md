import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;'); // Your project ID

const functions = new Functions(client);

const result = await functions.deleteExecution(
    '<FUNCTION_ID>', // functionId
    '<EXECUTION_ID>' // executionId
);

console.log(result);
