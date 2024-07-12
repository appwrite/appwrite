require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_email(
    message_id: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [], # optional
    users: [], # optional
    targets: [], # optional
    cc: [], # optional
    bcc: [], # optional
    attachments: [], # optional
    draft: false, # optional
    html: false, # optional
    scheduled_at: '' # optional
)
