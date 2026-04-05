from appwrite.client import Client
from appwrite.services.functions import Functions

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions(client)

result = functions.update_deployment_build(
    function_id = '<FUNCTION_ID>',
    deployment_id = '<DEPLOYMENT_ID>'
)
