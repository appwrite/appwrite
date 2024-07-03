import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

val account = Account(client)

val result = account.createMagicURLToken(
    userId = "<USER_ID>", 
    email = "email@example.com", 
    url = "https://example.com", // (optional)
    phrase = false, // (optional)
)