require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_email(
    message_id: '<MESSAGE_ID>',
    topics: [], # optional
    users: [], # optional
    targets: [], # optional
    subject: '<SUBJECT>', # optional
    content: '<CONTENT>', # optional
    draft: false, # optional
    html: false, # optional
    cc: [], # optional
    bcc: [], # optional
    scheduled_at: '' # optional
)
