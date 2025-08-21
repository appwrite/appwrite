require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDb.new(client)

result = tables_db.create_url_column(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    key: '',
    required: false,
    default: 'https://example.com', # optional
    array: false # optional
)
