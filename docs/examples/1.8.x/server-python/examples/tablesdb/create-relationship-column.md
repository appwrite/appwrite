from appwrite.client import Client
from appwrite.services.tables_db import TablesDB
from appwrite.enums import RelationshipType
from appwrite.enums import RelationMutate

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB(client)

result = tables_db.create_relationship_column(
    database_id = '<DATABASE_ID>',
    table_id = '<TABLE_ID>',
    related_table_id = '<RELATED_TABLE_ID>',
    type = RelationshipType.ONETOONE,
    two_way = False, # optional
    key = '', # optional
    two_way_key = '', # optional
    on_delete = RelationMutate.CASCADE # optional
)
