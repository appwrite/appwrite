from appwrite.client import Client
from appwrite.services.functions import Functions

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

functions = Functions(client)

result = functions.create_execution('[FUNCTION_ID]')
