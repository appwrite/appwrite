require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

tables_db = TablesDB.new(client)

result = tables_db.update_rows(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    data: {
        "username" => "walter.obrien",
        "email" => "walter.obrien@example.com",
        "fullName" => "Walter O'Brien",
        "age" => 33,
        "isAdmin" => false
    }, # optional
    queries: [], # optional
    transaction_id: '<TRANSACTION_ID>' # optional
)
