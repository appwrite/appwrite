from appwrite.client import Client

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

health = Health(client)

result = health.get_queue_usage_dump(
    threshold = None # optional
)
