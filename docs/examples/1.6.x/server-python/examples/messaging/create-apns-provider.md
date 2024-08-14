from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

messaging = Messaging(client)

result = messaging.create_apns_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',
    auth_key = '<AUTH_KEY>', # optional
    auth_key_id = '<AUTH_KEY_ID>', # optional
    team_id = '<TEAM_ID>', # optional
    bundle_id = '<BUNDLE_ID>', # optional
    sandbox = False, # optional
    enabled = False # optional
)
