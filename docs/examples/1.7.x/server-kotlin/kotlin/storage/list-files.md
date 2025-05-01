import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Storage

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val storage = Storage(client)

val response = storage.listFiles(
    bucketId = "<BUCKET_ID>",
    queries = listOf(), // optional
    search = "<SEARCH>" // optional
)
