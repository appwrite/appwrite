require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB.new(client)

result = tables_db.create_relationship_column(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    related_table_id: '<RELATED_TABLE_ID>',
    type: RelationshipType::ONETOONE,
    two_way: false, # optional
    key: '', # optional
    two_way_key: '', # optional
    on_delete: RelationMutate::CASCADE # optional
)
