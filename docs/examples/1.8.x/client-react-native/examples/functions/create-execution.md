import { Client, Functions, ExecutionMethod } from "react-native-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.createExecution({
    functionId: '<FUNCTION_ID>',
    body: '<BODY>',
    async: false,
    path: '<PATH>',
    method: ExecutionMethod.GET,
    headers: {},
    scheduledAt: '<SCHEDULED_AT>'
});

console.log(result);
