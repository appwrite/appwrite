import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.createTemplateDeployment(
    '<SITE_ID>', // siteId
    '<REPOSITORY>', // repository
    '<OWNER>', // owner
    '<ROOT_DIRECTORY>', // rootDirectory
    '<VERSION>', // version
    false // activate (optional)
);

console.log(result);
