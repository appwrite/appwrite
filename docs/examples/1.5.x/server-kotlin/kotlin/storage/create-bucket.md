import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val storage = Storage(client)

val response = storage.createBucket(
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
