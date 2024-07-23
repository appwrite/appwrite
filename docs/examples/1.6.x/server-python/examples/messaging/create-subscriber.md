from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_jwt('&lt;YOUR_JWT&gt;') # Your secret JSON Web Token

messaging = Messaging(client)

result = messaging.create_subscriber(
    topic_id = '<TOPIC_ID>',
    subscriber_id = '<SUBSCRIBER_ID>',
    target_id = '<TARGET_ID>'
)
