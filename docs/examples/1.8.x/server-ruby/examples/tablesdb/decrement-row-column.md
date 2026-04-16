require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

tables_db = TablesDB.new(client)

result = tables_db.decrement_row_column(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    row_id: '<ROW_ID>',
    column: '',
    value: null, # optional
    min: null, # optional
    transaction_id: '<TRANSACTION_ID>' # optional
)
