from appwrite.client import Client
from appwrite.services.storage import Storage

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

storage = Storage(client)

result = storage.update_file(
    bucket_id = '<BUCKET_ID>',
    file_id = '<FILE_ID>',
    name = '<NAME>', # optional
    permissions = ["read("any")"] # optional
)
