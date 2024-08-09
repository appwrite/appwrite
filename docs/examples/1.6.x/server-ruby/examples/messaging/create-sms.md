require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

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
