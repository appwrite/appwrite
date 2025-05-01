require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

tokens = Tokens.new(client)

result = tokens.create_file_token(
    bucket_id: '<BUCKET_ID>',
    file_id: '<FILE_ID>',
    expire: '', # optional
    permissions: ["read("any")"] # optional
)
