import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Account
import io.appwrite.enums.OAuthProvider

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val account = Account(client)

account.createOAuth2Token(
    provider = OAuthProvider.AMAZON,
    success = "https://example.com", // (optional)
    failure = "https://example.com", // (optional)
    scopes = listOf(), // (optional)
)