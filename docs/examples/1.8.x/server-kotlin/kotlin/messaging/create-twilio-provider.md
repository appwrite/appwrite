import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createTwilioProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>",
    from = "+12065550100", // optional
    accountSid = "<ACCOUNT_SID>", // optional
    authToken = "<AUTH_TOKEN>", // optional
    enabled = false // optional
)
