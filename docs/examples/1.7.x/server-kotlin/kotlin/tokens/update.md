import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tokens

val client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val tokens = Tokens(client)

val response = tokens.update(
    tokenId = "<TOKEN_ID>",
    expire = "", // optional
    permissions = listOf("read("any")") // optional
)
