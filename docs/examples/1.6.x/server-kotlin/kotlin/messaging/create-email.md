import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createEmail(
    messageId = "<MESSAGE_ID>",
    subject = "<SUBJECT>",
    content = "<CONTENT>",
    topics = listOf(), // optional
    users = listOf(), // optional
    targets = listOf(), // optional
    cc = listOf(), // optional
    bcc = listOf(), // optional
    attachments = listOf(), // optional
    draft = false, // optional
    html = false, // optional
    scheduledAt = "" // optional
)
