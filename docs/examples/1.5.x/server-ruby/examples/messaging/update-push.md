require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_push(
    message_id: '<MESSAGE_ID>',
    topics: [], # optional
    users: [], # optional
    targets: [], # optional
    title: '<TITLE>', # optional
    body: '<BODY>', # optional
    data: {}, # optional
    action: '<ACTION>', # optional
    image: '[ID1:ID2]', # optional
    icon: '<ICON>', # optional
    sound: '<SOUND>', # optional
    color: '<COLOR>', # optional
    tag: '<TAG>', # optional
    badge: null, # optional
    draft: false, # optional
    scheduled_at: '', # optional
    content_available: false, # optional
    critical: false, # optional
    priority: MessagePriority::NORMAL # optional
)
