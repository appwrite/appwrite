import { Client, Functions } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.listExecutions(
    '<FUNCTION_ID>', // functionId
    [] // queries (optional)
);

console.log(result);
