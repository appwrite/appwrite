from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging(client)

result = messaging.update_push(
    message_id = '<MESSAGE_ID>',
    topics = [], # optional
    users = [], # optional
    targets = [], # optional
    title = '<TITLE>', # optional
    body = '<BODY>', # optional
    data = {}, # optional
    action = '<ACTION>', # optional
    image = '[ID1:ID2]', # optional
    icon = '<ICON>', # optional
    sound = '<SOUND>', # optional
    color = '<COLOR>', # optional
    tag = '<TAG>', # optional
    badge = None, # optional
    draft = False, # optional
    scheduled_at = '' # optional
)
