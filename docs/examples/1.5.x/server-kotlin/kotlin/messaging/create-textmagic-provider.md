import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createTextmagicProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>",
    from = "+12065550100", // optional
    username = "<USERNAME>", // optional
    apiKey = "<API_KEY>", // optional
    enabled = false // optional
)
