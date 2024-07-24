from appwrite.client import Client
from appwrite.enums import 

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

health = Health(client)

result = health.get_failed_jobs(
    name = .V1_DATABASE,
    threshold = None # optional
)
