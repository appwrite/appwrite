import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let sites = Sites(client)

let bytes = try await sites.getDeploymentDownload(
    siteId: "<SITE_ID>",
    deploymentId: "<DEPLOYMENT_ID>",
    type: .source // optional
)

