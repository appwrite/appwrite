import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage
import io.appwrite.enums.Compression
import io.appwrite.Permission
import io.appwrite.Role

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val storage = Storage(client)

val response = storage.updateBucket(
    bucketId = "<BUCKET_ID>",
    name = "<NAME>",
    permissions = listOf(Permission.read(Role.any())), // optional
    fileSecurity = false, // optional
    enabled = false, // optional
    maximumFileSize = 1, // optional
    allowedFileExtensions = listOf(), // optional
    compression = "none", // optional
    encryption = false, // optional
    antivirus = false, // optional
    transformations = false // optional
)
