from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_admin('') # 
client.set_key('<YOUR_API_KEY>') # Your secret API key

databases = Databases(client)

result = databases.upsert_documents(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>'
)
