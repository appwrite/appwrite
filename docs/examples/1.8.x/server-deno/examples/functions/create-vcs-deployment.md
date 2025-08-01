import { Client, Functions, VCSDeploymentType } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const functions = new Functions(client);

const response = await functions.createVcsDeployment(
    '<FUNCTION_ID>', // functionId
    VCSDeploymentType.Branch, // type
    '<REFERENCE>', // reference
    false // activate (optional)
);
