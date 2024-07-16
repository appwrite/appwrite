require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

messaging = Messaging.new(client)

result = messaging.create_push(
    message_id: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [], # optional
    users: [], # optional
    targets: [], # optional
    data: {}, # optional
    action: '<ACTION>', # optional
    image: '[ID1:ID2]', # optional
    icon: '<ICON>', # optional
    sound: '<SOUND>', # optional
    color: '<COLOR>', # optional
    tag: '<TAG>', # optional
    badge: '<BADGE>', # optional
    draft: false, # optional
    scheduled_at: '' # optional
)
