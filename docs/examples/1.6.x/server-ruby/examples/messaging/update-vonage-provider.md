require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_vonage_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>', # optional
    enabled: false, # optional
    api_key: '<API_KEY>', # optional
    api_secret: '<API_SECRET>', # optional
    from: '<FROM>' # optional
)
