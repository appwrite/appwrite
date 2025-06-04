from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_jwt('<YOUR_JWT>') # Your secret JSON Web Token

messaging = Messaging(client)

result = messaging.create_subscriber(
    topic_id = '<TOPIC_ID>',
    subscriber_id = '<SUBSCRIBER_ID>',
    target_id = '<TARGET_ID>'
)
