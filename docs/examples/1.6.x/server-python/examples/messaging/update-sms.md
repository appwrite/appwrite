from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.update_sms(
    message_id = '<MESSAGE_ID>',
    topics = [], # optional
    users = [], # optional
    targets = [], # optional
    content = '<CONTENT>', # optional
    draft = False, # optional
    scheduled_at = '' # optional
)
