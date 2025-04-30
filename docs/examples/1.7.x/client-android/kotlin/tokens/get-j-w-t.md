import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tokens

val client = Client(context)
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val tokens = Tokens(client)

val result = tokens.getJWT(
    tokenId = "<TOKEN_ID>", 
)