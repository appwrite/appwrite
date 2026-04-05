import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val messaging = Messaging(client)

val result = messaging.deleteSubscriber(
    topicId = "<TOPIC_ID>", 
    subscriberId = "<SUBSCRIBER_ID>", 
)