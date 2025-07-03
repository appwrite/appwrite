from appwrite.client import Client
from appwrite.services.tables import Tables

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_session('') # The user session to authenticate with

tables = Tables(client)

result = tables.update_row(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    row_id = '<ROW_ID>',
    data = {}, # optional
    permissions = ["read("any")"] # optional
)
