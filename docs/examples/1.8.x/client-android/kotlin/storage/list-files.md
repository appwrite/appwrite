import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val storage = Storage(client)

val result = storage.listFiles(
    bucketId = "<BUCKET_ID>", 
    queries = listOf(), // (optional)
    search = "<SEARCH>", // (optional)
    total = false, // (optional)
)