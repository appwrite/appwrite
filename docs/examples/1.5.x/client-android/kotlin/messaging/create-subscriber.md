import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val messaging = Messaging(client)

val result = messaging.createSubscriber(
    topicId = "<TOPIC_ID>", 
    subscriberId = "<SUBSCRIBER_ID>", 
    targetId = "<TARGET_ID>", 
)