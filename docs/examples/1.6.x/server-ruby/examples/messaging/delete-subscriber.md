require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
    .set_jwt('&lt;YOUR_JWT&gt;') # Your secret JSON Web Token

messaging = Messaging.new(client)

result = messaging.delete_subscriber(
    topic_id: '<TOPIC_ID>',
    subscriber_id: '<SUBSCRIBER_ID>'
)
