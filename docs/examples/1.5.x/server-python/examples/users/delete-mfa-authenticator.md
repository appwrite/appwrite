from appwrite.client import Client
from appwrite.enums import AuthenticatorType

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

users = Users(client)

result = users.delete_mfa_authenticator(
    user_id = '<USER_ID>',
    type = AuthenticatorType.TOTP
)
