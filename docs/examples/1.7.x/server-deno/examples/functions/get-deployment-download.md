import { Client, Functions, DeploymentDownloadType } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new Functions(client);

const result = functions.getDeploymentDownload(
    '<FUNCTION_ID>', // functionId
    '<DEPLOYMENT_ID>', // deploymentId
    DeploymentDownloadType.Source // type (optional)
);
