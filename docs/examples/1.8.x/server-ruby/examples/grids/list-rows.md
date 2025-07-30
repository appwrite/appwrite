require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

grids = Grids.new(client)

result = grids.list_rows(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    queries: [] # optional
)
