from appwrite.client import Client
from appwrite.services.tables import Tables

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

tables = Tables(client)

result = tables.update_rows(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    data = {}, # optional
    queries = [] # optional
)
