from appwrite.client import Client
from appwrite.services.functions import Functions
from appwrite.enums.execution_method import ExecutionMethod

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

functions = Functions(client)

result = functions.create_execution(
    function_id = '<FUNCTION_ID>',
    body = '<BODY>', # optional
    xasync = False, # optional
    path = '<PATH>', # optional
    method = ExecutionMethod.GET, # optional
    headers = {}, # optional
    scheduled_at = '' # optional
)
