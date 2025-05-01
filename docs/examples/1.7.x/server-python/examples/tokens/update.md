from appwrite.client import Client
from appwrite.services.tokens import Tokens

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

tokens = Tokens(client)

result = tokens.update(
    token_id = '<TOKEN_ID>',
    expire = '', # optional
    permissions = ["read("any")"] # optional
)
