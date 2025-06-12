from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging(client)

result = messaging.create_smtp_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',
    host = '<HOST>',
    port = 1, # optional
    username = '<USERNAME>', # optional
    password = '<PASSWORD>', # optional
    encryption = SmtpEncryption.NONE, # optional
    auto_tls = False, # optional
    mailer = '<MAILER>', # optional
    from_name = '<FROM_NAME>', # optional
    from_email = 'email@example.com', # optional
    reply_to_name = '<REPLY_TO_NAME>', # optional
    reply_to_email = 'email@example.com', # optional
    enabled = False # optional
)
