import { Client, Functions, VCSReferenceType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.createVcsDeployment({
    functionId: '<FUNCTION_ID>',
    type: VCSReferenceType.Branch,
    reference: '<REFERENCE>',
    activate: false // optional
});

console.log(result);
