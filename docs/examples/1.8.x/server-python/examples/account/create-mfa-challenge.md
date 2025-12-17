from appwrite.client import Client
from appwrite.services.account import Account
from appwrite.enums import AuthenticationFactor

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

account = Account(client)

result = account.create_mfa_challenge(
    factor = AuthenticationFactor.EMAIL
)
