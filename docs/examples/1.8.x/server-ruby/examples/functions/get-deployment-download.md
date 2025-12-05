require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions.new(client)

result = functions.get_deployment_download(
    function_id: '<FUNCTION_ID>',
    deployment_id: '<DEPLOYMENT_ID>',
    type: DeploymentDownloadType::SOURCE # optional
)
