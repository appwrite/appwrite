require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

users = Users.new(client)

result = users.create_target(
    user_id: '<USER_ID>',
    target_id: '<TARGET_ID>',
    provider_type: MessagingProviderType::EMAIL,
    identifier: '<IDENTIFIER>',
    provider_id: '<PROVIDER_ID>', # optional
    name: '<NAME>' # optional
)
