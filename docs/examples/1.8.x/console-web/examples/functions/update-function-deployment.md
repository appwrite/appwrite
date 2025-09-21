import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.updateFunctionDeployment({
    functionId: '<FUNCTION_ID>',
    deploymentId: '<DEPLOYMENT_ID>'
});

console.log(result);
