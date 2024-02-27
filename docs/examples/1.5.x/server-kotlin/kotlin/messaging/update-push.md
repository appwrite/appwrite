import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val messaging = Messaging(client)

val response = messaging.updatePush(
    messageId = "<MESSAGE_ID>",
    topics = listOf(), // optional
    users = listOf(), // optional
    targets = listOf(), // optional
    title = "<TITLE>", // optional
    body = "<BODY>", // optional
    data = mapOf( "a" to "b" ), // optional
    action = "<ACTION>", // optional
    image = "[ID1:ID2]", // optional
    icon = "<ICON>", // optional
    sound = "<SOUND>", // optional
    color = "<COLOR>", // optional
    tag = "<TAG>", // optional
    badge = 0, // optional
    draft = false, // optional
    scheduledAt = "" // optional
)
