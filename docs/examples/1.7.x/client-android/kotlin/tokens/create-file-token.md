import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Tokens

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val tokens = Tokens(client)

val result = tokens.createFileToken(
    bucketId = "<BUCKET_ID>", 
    fileId = "<FILE_ID>", 
    expire = "", // (optional)
    permissions = listOf("read("any")"), // (optional)
)