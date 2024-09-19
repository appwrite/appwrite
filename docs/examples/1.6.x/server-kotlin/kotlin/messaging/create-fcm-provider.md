import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createFcmProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>",
    serviceAccountJSON = mapOf( "a" to "b" ), // optional
    enabled = false // optional
)
