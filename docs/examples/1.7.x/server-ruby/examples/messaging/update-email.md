require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

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
    scheduled_at: '', # optional
    attachments: [] # optional
)
