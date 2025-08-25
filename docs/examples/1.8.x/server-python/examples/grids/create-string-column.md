from appwrite.client import Client
from appwrite.services.grids import Grids

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

grids = Grids(client)

result = grids.create_string_column(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    key = '',
    size = 1,
    required = False,
    default = '<DEFAULT>', # optional
    array = False, # optional
    encrypt = False # optional
)
