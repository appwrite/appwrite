from appwrite.client import Client
from Appwrite.enums import OAuthProvider

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
)

account = Account(client)

result = account.create_o_auth2_session(OAuthProvider.AMAZON)
