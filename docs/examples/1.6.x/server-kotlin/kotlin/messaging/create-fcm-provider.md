import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createFcmProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>",
    serviceAccountJSON = mapOf( "a" to "b" ), // optional
    enabled = false // optional
)
