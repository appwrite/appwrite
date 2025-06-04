require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_sms(
    message_id: '<MESSAGE_ID>',
    content: '<CONTENT>',
    topics: [], # optional
    users: [], # optional
    targets: [], # optional
    draft: false, # optional
    scheduled_at: '' # optional
)
