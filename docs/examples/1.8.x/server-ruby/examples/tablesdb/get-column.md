require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB.new(client)

result = tables_db.get_column(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    key: ''
)
