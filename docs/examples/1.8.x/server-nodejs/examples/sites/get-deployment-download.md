const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const sites = new sdk.Sites(client);

const result = await sites.getDeploymentDownload(
    '<SITE_ID>', // siteId
    '<DEPLOYMENT_ID>', // deploymentId
    sdk.DeploymentDownloadType.Source // type (optional)
);
