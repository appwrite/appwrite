import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val messaging = Messaging(client)

val response = messaging.updateSmtpProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>", // optional
    host = "<HOST>", // optional
    port = 1, // optional
    username = "<USERNAME>", // optional
    password = "<PASSWORD>", // optional
    encryption = "none", // optional
    autoTLS = false, // optional
    mailer = "<MAILER>", // optional
    fromName = "<FROM_NAME>", // optional
    fromEmail = "email@example.com", // optional
    replyToName = "<REPLY_TO_NAME>", // optional
    replyToEmail = "<REPLY_TO_EMAIL>", // optional
    enabled = false // optional
)
