import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.updateVariable({
    functionId: '<FUNCTION_ID>',
    variableId: '<VARIABLE_ID>',
    key: '<KEY>',
    value: '<VALUE>', // optional
    secret: false // optional
});

console.log(result);
