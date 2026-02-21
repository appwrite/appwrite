require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.create_target(
    user_id: '<USER_ID>',
    target_id: '<TARGET_ID>',
    provider_type: MessagingProviderType::EMAIL,
    identifier: '<IDENTIFIER>',
    provider_id: '<PROVIDER_ID>', # optional
    name: '<NAME>' # optional
)
