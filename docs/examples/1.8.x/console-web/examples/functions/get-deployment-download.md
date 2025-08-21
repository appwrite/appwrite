import { Client, Functions, DeploymentDownloadType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = functions.getDeploymentDownload({
    functionId: '<FUNCTION_ID>',
    deploymentId: '<DEPLOYMENT_ID>',
    type: DeploymentDownloadType.Source
});

console.log(result);
