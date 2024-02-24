require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID

account = Account.new(client)

response = account.create_o_auth2_session(
    provider: OAuthProvider::AMAZON,
    success: 'https://example.com', # optional
    failure: 'https://example.com', # optional
    scopes: [] # optional
)

puts response.inspect
