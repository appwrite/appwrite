require 'appwrite'

include Appwrite
include Appwrite::Permission
include Appwrite::Role

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB.new(client)

result = tables_db.create_table(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    name: '<NAME>',
    permissions: [Permission.read(Role.any())], # optional
    row_security: false, # optional
    enabled: false, # optional
    columns: [], # optional
    indexes: [] # optional
)
