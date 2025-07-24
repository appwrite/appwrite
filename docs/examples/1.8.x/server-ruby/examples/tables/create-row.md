require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_session('') # The user session to authenticate with
    .set_key('<YOUR_API_KEY>') # Your secret API key
    .set_jwt('<YOUR_JWT>') # Your secret JSON Web Token

tables = Tables.new(client)

result = tables.create_row(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    row_id: '<ROW_ID>',
    data: {},
    permissions: ["read("any")"] # optional
)
