from appwrite.client import Client
from appwrite.services.databases import Databases
from appwrite.enums import RelationshipType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

databases = Databases(client)

result = databases.create_relationship_attribute(
    database_id = '<DATABASE_ID>',
    collection_id = '<COLLECTION_ID>',
    related_collection_id = '<RELATED_COLLECTION_ID>',
    type = RelationshipType.ONETOONE,
    two_way = False, # optional
    key = '', # optional
    two_way_key = '', # optional
    on_delete = RelationMutate.CASCADE # optional
)
