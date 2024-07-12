require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

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
    scheduled_at: '' # optional
)
