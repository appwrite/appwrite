import { Client, Sites, VCSDeploymentType } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.createVcsDeployment({
    siteId: '<SITE_ID>',
    type: VCSDeploymentType.Branch,
    reference: '<REFERENCE>',
    activate: false
});

console.log(result);
