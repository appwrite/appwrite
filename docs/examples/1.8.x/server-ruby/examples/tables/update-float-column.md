require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables = Tables.new(client)

result = tables.update_float_column(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    key: '',
    required: false,
    default: null,
    min: null, # optional
    max: null, # optional
    new_key: '' # optional
)
