import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

val storage = Storage(client)

val response = storage.updateBucket(
    bucketId = "<BUCKET_ID>",
    name = "<NAME>",
    permissions = listOf("read("any")"), // optional
    fileSecurity = false, // optional
    enabled = false, // optional
    maximumFileSize = 1, // optional
    allowedFileExtensions = listOf(), // optional
    compression = "none", // optional
    encryption = false, // optional
    antivirus = false // optional
)
