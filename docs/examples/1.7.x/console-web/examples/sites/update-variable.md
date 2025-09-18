import { Client, Sites } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const sites = new Sites(client);

const result = await sites.updateVariable(
    '<SITE_ID>', // siteId
    '<VARIABLE_ID>', // variableId
    '<KEY>', // key
    '<VALUE>', // value (optional)
    false // secret (optional)
);

console.log(result);
