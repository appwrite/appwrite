require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.update_target(
    user_id: '<USER_ID>',
    target_id: '<TARGET_ID>',
    identifier: '<IDENTIFIER>', # optional
    provider_id: '<PROVIDER_ID>', # optional
    name: '<NAME>' # optional
)
