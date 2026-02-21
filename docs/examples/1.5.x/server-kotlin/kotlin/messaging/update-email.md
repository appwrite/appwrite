import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.updateEmail(
    messageId = "<MESSAGE_ID>",
    topics = listOf(), // optional
    users = listOf(), // optional
    targets = listOf(), // optional
    subject = "<SUBJECT>", // optional
    content = "<CONTENT>", // optional
    draft = false, // optional
    html = false, // optional
    cc = listOf(), // optional
    bcc = listOf(), // optional
    scheduledAt = "", // optional
    attachments = listOf() // optional
)
