from appwrite.client import Client
from appwrite.services.users import Users
from appwrite.enums import AuthenticatorType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

users = Users(client)

result = users.delete_mfa_authenticator(
    user_id = '<USER_ID>',
    type = AuthenticatorType.TOTP
)
