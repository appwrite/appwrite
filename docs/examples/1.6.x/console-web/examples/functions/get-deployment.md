import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.getDeployment(
    '<FUNCTION_ID>', // functionId
    '<DEPLOYMENT_ID>' // deploymentId
);

console.log(result);
