import { Client, Sites, DeploymentDownloadType } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new Sites(client);

const result = sites.getDeploymentDownload(
    '<SITE_ID>', // siteId
    '<DEPLOYMENT_ID>', // deploymentId
    DeploymentDownloadType.Source // type (optional)
);
