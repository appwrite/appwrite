from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.create_textmagic_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',
    from = '+12065550100', # optional
    username = '<USERNAME>', # optional
    api_key = '<API_KEY>', # optional
    enabled = False # optional
)
