require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_admin('') # 
    .set_session('') # The user session to authenticate with
    .set_key('<YOUR_API_KEY>') # Your secret API key
    .set_jwt('<YOUR_JWT>') # Your secret JSON Web Token

databases = Databases.new(client)

result = databases.create_document(
    database_id: '<DATABASE_ID>',
    collection_id: '<COLLECTION_ID>',
    document_id: '<DOCUMENT_ID>',
    data: {},
    permissions: ["read("any")"] # optional
)
