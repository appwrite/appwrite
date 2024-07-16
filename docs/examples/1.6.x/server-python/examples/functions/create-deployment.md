from appwrite.client import Client
from appwrite.input_file import InputFile

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

functions = Functions(client)

result = functions.create_deployment(
    function_id = '<FUNCTION_ID>',
    code = InputFile.from_path('file.png'),
    activate = False,
    entrypoint = '<ENTRYPOINT>', # optional
    commands = '<COMMANDS>' # optional
)
