from appwrite.client import Client
from appwrite.enums import RelationshipType

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

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
