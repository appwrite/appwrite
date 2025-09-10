require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

databases = Databases.new(client)

result = databases.create_document(
    database_id: '<DATABASE_ID>',
    collection_id: '<COLLECTION_ID>',
    document_id: '<DOCUMENT_ID>',
    data: {
        "username" => "walter.obrien",
        "email" => "walter.obrien@example.com",
        "fullName" => "Walter O'Brien",
        "age" => 30,
        "isAdmin" => false
    },
    permissions: ["read("any")"] # optional
)
