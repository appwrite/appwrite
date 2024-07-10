import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setJWT("&lt;YOUR_JWT&gt;") // Your secret JSON Web Token

val messaging = Messaging(client)

val response = messaging.createSubscriber(
    topicId = "<TOPIC_ID>",
    subscriberId = "<SUBSCRIBER_ID>",
    targetId = "<TARGET_ID>"
)
