import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging
import io.appwrite.enums.MessagePriority

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

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
    image = "<ID1:ID2>", // optional
    icon = "<ICON>", // optional
    sound = "<SOUND>", // optional
    color = "<COLOR>", // optional
    tag = "<TAG>", // optional
    badge = 0, // optional
    draft = false, // optional
    scheduledAt = "", // optional
    contentAvailable = false, // optional
    critical = false, // optional
    priority = "normal" // optional
)
