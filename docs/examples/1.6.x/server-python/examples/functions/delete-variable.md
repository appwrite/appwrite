from appwrite.client import Client
from appwrite.services.functions import Functions

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions(client)

result = functions.delete_variable(
    function_id = '<FUNCTION_ID>',
    variable_id = '<VARIABLE_ID>'
)
