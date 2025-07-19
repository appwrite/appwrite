from appwrite.client import Client
from appwrite.services.tables import Tables

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_session('') # The user session to authenticate with
client.set_key('<YOUR_API_KEY>') # Your secret API key
client.set_jwt('<YOUR_JWT>') # Your secret JSON Web Token

tables = Tables(client)

result = tables.create_row(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    row_id = '<ROW_ID>',
    data = {},
    permissions = ["read("any")"] # optional
)
