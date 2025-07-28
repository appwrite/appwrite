from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_session('') # The user session to authenticate with
client.set_key('<YOUR_API_KEY>') # Your secret API key
client.set_jwt('<YOUR_JWT>') # Your secret JSON Web Token

databases = Databases(client)

result = databases.upsert_document(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>'
)
