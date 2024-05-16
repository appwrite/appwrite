require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_telesign_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>', # optional
    enabled: false, # optional
    customer_id: '<CUSTOMER_ID>', # optional
    api_key: '<API_KEY>', # optional
    from: '<FROM>' # optional
)
