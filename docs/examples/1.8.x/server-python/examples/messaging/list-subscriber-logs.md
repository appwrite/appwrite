from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.list_subscriber_logs(
    subscriber_id = '<SUBSCRIBER_ID>',
    queries = [], # optional
    total = False # optional
)
