require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites.new(client)

result = sites.get_deployment_download(
    site_id: '<SITE_ID>',
    deployment_id: '<DEPLOYMENT_ID>',
    type: DeploymentDownloadType::SOURCE # optional
)
