import { Client, Functions, VCSDeploymentType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.createVcsDeployment(
    '<FUNCTION_ID>', // functionId
    VCSDeploymentType.Branch, // type
    '<REFERENCE>', // reference
    false // activate (optional)
);

console.log(result);
