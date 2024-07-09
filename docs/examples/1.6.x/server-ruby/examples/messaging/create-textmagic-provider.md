require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_textmagic_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>',
    from: '+12065550100', # optional
    username: '<USERNAME>', # optional
    api_key: '<API_KEY>', # optional
    enabled: false # optional
)
