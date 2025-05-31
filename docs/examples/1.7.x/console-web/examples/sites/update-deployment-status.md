import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.updateDeploymentStatus(
    '<SITE_ID>', // siteId
    '<DEPLOYMENT_ID>' // deploymentId
);

console.log(result);
