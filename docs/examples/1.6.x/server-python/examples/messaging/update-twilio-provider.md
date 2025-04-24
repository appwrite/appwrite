from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.update_twilio_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>', # optional
    enabled = False, # optional
    account_sid = '<ACCOUNT_SID>', # optional
    auth_token = '<AUTH_TOKEN>', # optional
    from = '<FROM>' # optional
)
