require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://example.com/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_session('') # The user session to authenticate with

tokens = Tokens.new(client)

result = tokens.update(
    token_id: '<TOKEN_ID>',
    expire: '', # optional
    permissions: ["read("any")"] # optional
)
