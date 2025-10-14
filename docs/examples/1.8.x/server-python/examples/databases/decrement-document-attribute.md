from appwrite.client import Client
from appwrite.services.databases import Databases

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

databases = Databases(client)

result = databases.decrement_document_attribute(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    document_id = '<DOCUMENT_ID>',
    attribute = '',
    value = None, # optional
    min = None, # optional
    transaction_id = '<TRANSACTION_ID>' # optional
)
