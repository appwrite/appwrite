from appwrite.client import Client
from appwrite.services.avatars import Avatars

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

avatars = Avatars(client)

result = avatars.get_qr(
    text = '<TEXT>',
    size = 1, # optional
    margin = 0, # optional
    download = False # optional
)
