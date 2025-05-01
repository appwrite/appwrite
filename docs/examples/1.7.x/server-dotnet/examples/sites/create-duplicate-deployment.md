using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Sites sites = new Sites(client);

Deployment result = await sites.CreateDuplicateDeployment(
    siteId: "<SITE_ID>",
    deploymentId: "<DEPLOYMENT_ID>"
);