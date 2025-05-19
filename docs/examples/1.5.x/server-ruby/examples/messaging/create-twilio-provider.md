require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_twilio_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>',
    from: '+12065550100', # optional
    account_sid: '<ACCOUNT_SID>', # optional
    auth_token: '<AUTH_TOKEN>', # optional
    enabled: false # optional
)
