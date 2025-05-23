import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

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
