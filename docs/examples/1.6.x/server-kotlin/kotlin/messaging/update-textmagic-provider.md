import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

val messaging = Messaging(client)

val response = messaging.updateTextmagicProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>", // optional
    enabled = false, // optional
    username = "<USERNAME>", // optional
    apiKey = "<API_KEY>", // optional
    from = "<FROM>" // optional
)
