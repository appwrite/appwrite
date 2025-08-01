import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setJWT("<YOUR_JWT>") // Your secret JSON Web Token

val messaging = Messaging(client)

val response = messaging.createSubscriber(
    topicId = "<TOPIC_ID>",
    subscriberId = "<SUBSCRIBER_ID>",
    targetId = "<TARGET_ID>"
)
