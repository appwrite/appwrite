from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

messaging = Messaging(client)

result = messaging.create_textmagic_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',
    from = '+12065550100', # optional
    username = '<USERNAME>', # optional
    api_key = '<API_KEY>', # optional
    enabled = False # optional
)
