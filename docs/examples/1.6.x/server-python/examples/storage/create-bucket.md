from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

storage = Storage(client)

result = storage.create_bucket(
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
