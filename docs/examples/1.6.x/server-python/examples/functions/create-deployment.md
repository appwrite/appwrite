from appwrite.client import Client
from appwrite.services.functions import Functions
from appwrite.input_file import InputFile

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

functions = Functions(client)

result = functions.create_deployment(
    function_id = '<FUNCTION_ID>',
    code = InputFile.from_path('file.png'),
    activate = False,
    entrypoint = '<ENTRYPOINT>', # optional
    commands = '<COMMANDS>' # optional
)
