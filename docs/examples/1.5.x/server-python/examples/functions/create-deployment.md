from appwrite.client import Client
from appwrite.input_file import InputFile

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

functions = Functions(client)

result = functions.create_deployment(
    function_id = '<FUNCTION_ID>',
    code = InputFile.from_path('file.png'),
    activate = False,
    entrypoint = '<ENTRYPOINT>', # optional
    commands = '<COMMANDS>' # optional
)
