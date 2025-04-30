from appwrite.client import Client
from appwrite.services.tokens import Tokens

client = Client()
client.set_endpoint('https://example.com/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

tokens = Tokens(client)

result = tokens.list(
    bucket_id = '<BUCKET_ID>',
    file_id = '<FILE_ID>',
    queries = [] # optional
)
