from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging(client)

result = messaging.create_msg91_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',
    from = '+12065550100', # optional
    sender_id = '<SENDER_ID>', # optional
    auth_key = '<AUTH_KEY>', # optional
    enabled = False # optional
)
