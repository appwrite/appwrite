from appwrite.client import Client
from appwrite.enums import IndexType

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

databases = Databases(client)

result = databases.create_index(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    key = '',
    type = IndexType.KEY,
    attributes = [],
    orders = [] # optional
)
