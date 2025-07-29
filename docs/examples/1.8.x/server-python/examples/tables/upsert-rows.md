from appwrite.client import Client
from appwrite.services.tables import Tables

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_admin('') # 
client.set_key('<YOUR_API_KEY>') # Your secret API key

tables = Tables(client)

result = tables.upsert_rows(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>'
)
