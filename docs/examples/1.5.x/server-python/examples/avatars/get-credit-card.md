from appwrite.client import Client
from appwrite.services.avatars import Avatars
from appwrite.enums import CreditCard

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

avatars = Avatars(client)

result = avatars.get_credit_card(
    code = CreditCard.AMERICAN_EXPRESS,
    width = 0, # optional
    height = 0, # optional
    quality = 0 # optional
)
