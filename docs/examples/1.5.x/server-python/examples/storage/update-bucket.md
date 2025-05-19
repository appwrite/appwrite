from appwrite.client import Client
from appwrite.services.storage import Storage

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

storage = Storage(client)

result = storage.update_bucket(
    bucket_id = '<BUCKET_ID>',
    name = '<NAME>',
    permissions = ["read("any")"], # optional
    file_security = False, # optional
    enabled = False, # optional
    maximum_file_size = 1, # optional
    allowed_file_extensions = [], # optional
    compression = .NONE, # optional
    encryption = False, # optional
    antivirus = False # optional
)
