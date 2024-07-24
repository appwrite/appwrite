import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

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
