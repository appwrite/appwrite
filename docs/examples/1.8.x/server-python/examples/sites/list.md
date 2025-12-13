from appwrite.client import Client
from appwrite.services.sites import Sites

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

sites = Sites(client)

result = sites.list(
    queries = [], # optional
    search = '<SEARCH>', # optional
    total = False # optional
)
