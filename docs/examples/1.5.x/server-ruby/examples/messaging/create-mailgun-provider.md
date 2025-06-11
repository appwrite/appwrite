require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_mailgun_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>',
    api_key: '<API_KEY>', # optional
    domain: '<DOMAIN>', # optional
    is_eu_region: false, # optional
    from_name: '<FROM_NAME>', # optional
    from_email: 'email@example.com', # optional
    reply_to_name: '<REPLY_TO_NAME>', # optional
    reply_to_email: 'email@example.com', # optional
    enabled: false # optional
)
