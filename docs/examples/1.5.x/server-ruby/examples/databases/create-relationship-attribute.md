require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

databases = Databases.new(client)

result = databases.create_relationship_attribute(
    database_id: '<DATABASE_ID>',
    collection_id: '<COLLECTION_ID>',
    related_collection_id: '<RELATED_COLLECTION_ID>',
    type: RelationshipType::ONETOONE,
    two_way: false, # optional
    key: '', # optional
    two_way_key: '', # optional
    on_delete: RelationMutate::CASCADE # optional
)
