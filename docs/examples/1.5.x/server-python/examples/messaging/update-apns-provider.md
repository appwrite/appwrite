from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging(client)

result = messaging.update_apns_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>', # optional
    enabled = False, # optional
    auth_key = '<AUTH_KEY>', # optional
    auth_key_id = '<AUTH_KEY_ID>', # optional
    team_id = '<TEAM_ID>', # optional
    bundle_id = '<BUNDLE_ID>', # optional
    sandbox = False # optional
)
