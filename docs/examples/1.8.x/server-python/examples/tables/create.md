from appwrite.client import Client
from appwrite.services.tables import Tables

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

tables = Tables(client)

result = tables.create(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    name = '<NAME>',
    permissions = ["read("any")"], # optional
    row_security = False, # optional
    enabled = False # optional
)
