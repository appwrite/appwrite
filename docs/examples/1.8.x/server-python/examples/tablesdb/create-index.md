from appwrite.client import Client
from appwrite.services.tables_db import TablesDB
from appwrite.enums import IndexType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB(client)

result = tables_db.create_index(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    key = '',
    type = IndexType.KEY,
    columns = [],
    orders = [], # optional
    lengths = [] # optional
)
